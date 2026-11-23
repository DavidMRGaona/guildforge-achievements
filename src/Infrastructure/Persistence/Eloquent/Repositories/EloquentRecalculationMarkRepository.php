<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Achievements\Domain\Repositories\RecalculationMarkRepositoryInterface;

final readonly class EloquentRecalculationMarkRepository implements RecalculationMarkRepositoryInterface
{
    public const string TABLE = 'achievements_recalculations';

    private const string STORAGE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private ConnectionInterface $db,
        private TimezoneServiceInterface $time,
    ) {}

    public function markComplete(string $sourceType): void
    {
        $now = $this->time->toStorage(new DateTimeImmutable('now'))->format(self::STORAGE_FORMAT);

        $this->db->table(self::TABLE)->upsert(
            [['source_type' => $sourceType, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now]],
            ['source_type'],
            ['completed_at', 'updated_at'],
        );
    }

    public function completedAmong(array $sourceTypes): array
    {
        if ($sourceTypes === []) {
            return [];
        }

        return array_values(array_map(
            strval(...),
            $this->db->table(self::TABLE)->whereIn('source_type', $sourceTypes)->orderBy('source_type')->pluck('source_type')->all(),
        ));
    }
}
