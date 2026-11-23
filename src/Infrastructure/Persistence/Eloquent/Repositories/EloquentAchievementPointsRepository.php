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
 * achievement, answered from the grants' unique key (user_id first). The photo's query joins `users`,
 * so an account deleted for good (its grants stay without a foreign key) never counts.
 */
final readonly class EloquentAchievementPointsRepository implements AchievementPointsRepositoryInterface
{
    private const int CHUNK = 500;

    private const string STORAGE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private ConnectionInterface $db,
        private TimezoneServiceInterface $time,
    ) {}

    public function breakdowns(array $userIds, PointsPeriod $period, ?DateTimeImmutable $before = null): array
    {
        $userIds = array_values(array_unique(array_map(strtolower(...), $userIds)));
        $rows = [];

        foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
            foreach ($this->points($period, $before)->whereIn('achievements_grants.user_id', $chunk)->get() as $row) {
                $rows[] = $row;
            }
        }

        return $this->fold($rows) + array_fill_keys($userIds, new PointsBreakdown);
    }

    public function everyone(PointsPeriod $period, ?DateTimeImmutable $before = null): array
    {
        $rows = $this->points($period, $before)
            ->join('users', 'users.id', '=', 'achievements_grants.user_id')
            ->whereNull('users.deleted_at')
            ->whereNull('users.anonymized_at')
            ->get()
            ->all();

        return array_filter($this->fold($rows), static fn (PointsBreakdown $points): bool => $points->total() > 0);
    }

    public function openIds(): array
    {
        $ids = [];

        foreach ($this->db->table('achievements_catalogue')->where('is_secret', false)->pluck('id') as $id) {
            $ids[strtolower((string) $id)] = true;
        }

        return $ids;
    }

    public function firstGrantedAt(?DateTimeImmutable $before = null): ?DateTimeImmutable
    {
        $first = $this->before($this->db->table('achievements_grants'), $before)->min('granted_at');

        if (! is_string($first) || $first === '') {
            return null;
        }

        $read = DateTimeImmutable::createFromFormat(self::STORAGE_FORMAT, substr($first, 0, 19), $this->time->storageTimezone());

        return $read === false ? null : $read;
    }

    public function earliestSeasonKey(?DateTimeImmutable $before = null): ?string
    {
        $key = $this->before($this->db->table('achievements_grants'), $before)->where('season_key', '<>', '')->min('season_key');

        return is_string($key) && preg_match('/^\d{4}$/D', $key) === 1 ? $key : null;
    }

    /**
     * Only grants made before the instant, when there is one (compared in the storage zone).
     */
    private function before(Builder $query, ?DateTimeImmutable $before): Builder
    {
        return $before instanceof DateTimeImmutable
            ? $query->where('achievements_grants.granted_at', '<', $this->time->toStorage($before)->format(self::STORAGE_FORMAT))
            : $query;
    }

    /**
     * Rows of (account, achievement, points) folded into each account's breakdown.
     *
     * @param  array<int, stdClass>  $rows
     * @return array<string, PointsBreakdown>
     */
    private function fold(array $rows): array
    {
        $points = [];

        foreach ($rows as $row) {
            $points[strtolower((string) $row->user_id)][strtolower((string) $row->achievement_id)] = (int) $row->points;
        }

        return array_map(static fn (array $byAchievement): PointsBreakdown => new PointsBreakdown($byAchievement), $points);
    }

    /**
     * The grants of the period, their achievement's points summed per account and achievement (a
     * repeatable one counts once per season).
     */
    private function points(PointsPeriod $period, ?DateTimeImmutable $before): Builder
    {
        $query = $this->before($this->db->table('achievements_grants'), $before)
            ->join('achievements_catalogue', 'achievements_catalogue.id', '=', 'achievements_grants.achievement_id')
            ->select(['achievements_grants.user_id', 'achievements_catalogue.id as achievement_id'])
            ->selectRaw('sum(achievements_catalogue.points) as points')
            ->groupBy('achievements_grants.user_id', 'achievements_catalogue.id');

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
