<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use JsonException;
use Modules\Achievements\Domain\Repositories\PointsSnapshotRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\PointsBreakdown;

final readonly class EloquentPointsSnapshotRepository implements PointsSnapshotRepositoryInterface
{
    public const string TABLE = 'achievements_points_snapshots';

    public const string RUNS_TABLE = 'achievements_snapshot_runs';

    public const string RUN = 'points';

    private const int CHUNK = 500;

    private const string STORAGE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private ConnectionInterface $db,
        private TimezoneServiceInterface $time,
    ) {}

    public function replace(array $periods, DateTimeImmutable $takenAt): void
    {
        $this->db->transaction(function () use ($periods, $takenAt): void {
            $this->table()->delete();

            foreach ($periods as $periodKey => $accounts) {
                $rows = [];

                foreach ($accounts as $userId => $points) {
                    $rows[] = [
                        'period_key' => $periodKey,
                        'user_id' => strtolower($userId),
                        'points' => json_encode((object) $points->points, JSON_THROW_ON_ERROR),
                    ];
                }

                foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                    $this->table()->insert($chunk);
                }
            }

            $this->db->table(self::RUNS_TABLE)->upsert(
                [['name' => self::RUN, 'taken_at' => $this->time->toStorage($takenAt)->format(self::STORAGE_FORMAT)]],
                ['name'],
                ['taken_at'],
            );
        });
    }

    public function takenAt(): ?DateTimeImmutable
    {
        $taken = $this->db->table(self::RUNS_TABLE)->where('name', self::RUN)->value('taken_at');

        if (! is_string($taken) || $taken === '') {
            return null;
        }

        $read = DateTimeImmutable::createFromFormat(self::STORAGE_FORMAT, substr($taken, 0, 19), $this->time->storageTimezone());

        return $read === false ? null : $read;
    }

    public function forPeriod(string $periodKey): array
    {
        return $this->read($this->table()->where('period_key', $periodKey));
    }

    public function forUsers(array $userIds, string $periodKey): array
    {
        $userIds = array_values(array_unique(array_map(strtolower(...), $userIds)));
        $found = [];

        foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
            $found += $this->read($this->table()->where('period_key', $periodKey)->whereIn('user_id', $chunk));
        }

        return $found;
    }

    public function deleteForUser(string $userId): void
    {
        $this->table()->where('user_id', strtolower($userId))->delete();
    }

    /**
     * @return array<string, PointsBreakdown>
     */
    private function read(Builder $query): array
    {
        $found = [];

        foreach ($query->get(['user_id', 'points']) as $row) {
            $found[strtolower((string) $row->user_id)] = new PointsBreakdown($this->points($row->points));
        }

        return $found;
    }

    /**
     * Anything but a JSON object of id => points ≥ 0 reads as no points: never more than was kept.
     *
     * @return array<string, int>
     */
    private function points(mixed $stored): array
    {
        if (! is_string($stored) || $stored === '') {
            return [];
        }

        try {
            $decoded = json_decode($stored, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $points = [];

        foreach ($decoded as $id => $value) {
            if (is_string($id) && is_int($value) && $value >= 0) {
                $points[strtolower($id)] = $value;
            }
        }

        return $points;
    }

    private function table(): Builder
    {
        return $this->db->table(self::TABLE);
    }
}
