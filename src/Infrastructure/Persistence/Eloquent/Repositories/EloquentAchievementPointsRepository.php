<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Achievements\Domain\Repositories\AchievementPointsRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\PointsPeriod;

/**
 * Sums over `achievements_grants` joined to the catalogue, answered from the grants' unique key
 * (user_id first). The standings join `users` (so an account deleted for good, whose grants stay
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

    public function totals(array $userIds, PointsPeriod $period): array
    {
        $userIds = array_values(array_unique(array_map(strtolower(...), $userIds)));
        $totals = array_fill_keys($userIds, 0);

        foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
            $rows = $this->points($period)
                ->whereIn('achievements_grants.user_id', $chunk)
                ->groupBy('achievements_grants.user_id')
                ->addSelect('achievements_grants.user_id')
                ->get();

            foreach ($rows as $row) {
                $totals[strtolower((string) $row->user_id)] = (int) $row->points;
            }
        }

        return $totals;
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
            ->groupBy('achievements_grants.user_id')
            ->havingRaw('sum(achievements_catalogue.points) > 0')
            ->orderByRaw('sum(achievements_catalogue.points) desc')
            ->orderBy('achievements_grants.user_id')
            ->addSelect('achievements_grants.user_id')
            ->get();

        $standings = [];

        foreach ($rows as $row) {
            $standings[] = ['userId' => strtolower((string) $row->user_id), 'points' => (int) $row->points];
        }

        return $standings;
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
     * The grants of the period with their achievement's points summed per account.
     */
    private function points(PointsPeriod $period): Builder
    {
        $query = $this->db->table('achievements_grants')
            ->join('achievements_catalogue', 'achievements_catalogue.id', '=', 'achievements_grants.achievement_id')
            ->selectRaw('sum(achievements_catalogue.points) as points');

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
