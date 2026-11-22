<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Achievements\Domain\Repositories\AchievementPointsRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\PointsBreakdown;
use Modules\Achievements\Domain\ValueObjects\PointsPeriod;
use stdClass;

/**
 * One aggregate query over `achievements_grants` joined to the catalogue, per account and
 * achievement, answered from the grants' unique key (user_id first). The standings join `users` (so an account deleted for good, whose grants stay
 * without a foreign key, never counts) and the choices (no row: shown).
 */
final readonly class EloquentAchievementPointsRepository implements AchievementPointsRepositoryInterface
{
    private const int CHUNK = 500;

    private const string STORAGE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private ConnectionInterface $db,
        private TimezoneServiceInterface $time,
    ) {}

    public function breakdowns(array $userIds, PointsPeriod $period): array
    {
        $userIds = array_values(array_unique(array_map(strtolower(...), $userIds)));
        $rows = [];

        foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
            foreach ($this->points($period)->whereIn('achievements_grants.user_id', $chunk)->get() as $row) {
                $rows[] = $row;
            }
        }

        return $this->fold($rows) + array_fill_keys($userIds, new PointsBreakdown);
    }

    public function standings(PointsPeriod $period): array
    {
        $rows = $this->points($period)
            ->join('users', 'users.id', '=', 'achievements_grants.user_id')
            ->leftJoin(EloquentAchievementPreferencesRepository::TABLE.' as preferences', 'preferences.user_id', '=', 'achievements_grants.user_id')
            ->whereNull('users.deleted_at')
            ->whereNull('users.anonymized_at')
            // No row means nothing chosen: shown, and in the leaderboard
            ->where(static fn (Builder $query): Builder => $query->whereNull('preferences.user_id')->orWhere(
                static fn (Builder $chosen): Builder => $chosen->where('preferences.hidden', false)->where('preferences.in_leaderboard', true),
            ))
            ->get()
            ->all();

        return array_filter($this->fold($rows), static fn (PointsBreakdown $points): bool => $points->total() > 0);
    }

    public function firstGrantedAt(): ?DateTimeImmutable
    {
        $first = $this->db->table('achievements_grants')->min('granted_at');

        if (! is_string($first) || $first === '') {
            return null;
        }

        $read = DateTimeImmutable::createFromFormat(self::STORAGE_FORMAT, substr($first, 0, 19), $this->time->storageTimezone());

        return $read === false ? null : $read;
    }

    /**
     * Rows of (account, achievement, secret, points) folded into each account's breakdown.
     *
     * @param  array<int, stdClass>  $rows
     * @return array<string, PointsBreakdown>
     */
    private function fold(array $rows): array
    {
        $open = [];
        $secrets = [];

        foreach ($rows as $row) {
            $userId = strtolower((string) $row->user_id);
            $points = (int) $row->points;
            $open[$userId] ??= 0;

            if ((bool) $row->is_secret) {
                $secrets[$userId][strtolower((string) $row->achievement_id)] = $points;
            } else {
                $open[$userId] += $points;
            }
        }

        $breakdowns = [];

        foreach ($open as $userId => $points) {
            $breakdowns[$userId] = new PointsBreakdown($points, $secrets[$userId] ?? []);
        }

        return $breakdowns;
    }

    /**
     * The grants of the period, their achievement's points summed per account and achievement (a
     * repeatable one counts once per season), with whether it is secret.
     */
    private function points(PointsPeriod $period): Builder
    {
        $query = $this->db->table('achievements_grants')
            ->join('achievements_catalogue', 'achievements_catalogue.id', '=', 'achievements_grants.achievement_id')
            ->select(['achievements_grants.user_id', 'achievements_catalogue.id as achievement_id', 'achievements_catalogue.is_secret'])
            ->selectRaw('sum(achievements_catalogue.points) as points')
            ->groupBy('achievements_grants.user_id', 'achievements_catalogue.id', 'achievements_catalogue.is_secret');

        if ($period->isAllTime() || ! $period->from instanceof DateTimeImmutable || ! $period->until instanceof DateTimeImmutable) {
            return $query;
        }

        $from = $period->from->format(self::STORAGE_FORMAT);
        $until = $period->until->format(self::STORAGE_FORMAT);

        // Granted for the season (a repeatable achievement), or without a season during it
        return $query->where(static fn (Builder $grants): Builder => $grants
            ->where('achievements_grants.season_key', (string) $period->seasonKey())
            ->orWhere(static fn (Builder $seasonless): Builder => $seasonless
                ->where('achievements_grants.season_key', '')
                ->where('achievements_grants.granted_at', '>=', $from)
                ->where('achievements_grants.granted_at', '<', $until)));
    }
}
