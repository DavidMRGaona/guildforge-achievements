<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use App\Application\Activity\DTOs\ActivityDescriptionsResultDTO;
use App\Application\Activity\DTOs\ActivityFactsResultDTO;
use App\Application\Activity\DTOs\ActivityHistoryResultDTO;
use App\Application\Activity\Services\ActivityReaderInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The core's reader, with every subject's facts read as failed.
 */
final readonly class FailingFactsReader implements ActivityReaderInterface
{
    public function __construct(
        private ActivityReaderInterface $real,
    ) {}

    public function sources(): array
    {
        return $this->real->sources();
    }

    public function facts(string $type, string $subjectId): ActivityFactsResultDTO
    {
        return ActivityFactsResultDTO::failed();
    }

    public function history(string $type, ?string $cursor, int $limit): ActivityHistoryResultDTO
    {
        return $this->real->history($type, $cursor, $limit);
    }

    public function describe(string $type, array $subjectIds, ?Authenticatable $viewer): ActivityDescriptionsResultDTO
    {
        return $this->real->describe($type, $subjectIds, $viewer);
    }

    public function attributes(string $type): array
    {
        return $this->real->attributes($type);
    }

    public function attributeValues(string $type, string $key): array
    {
        return $this->real->attributeValues($type, $key);
    }
}
