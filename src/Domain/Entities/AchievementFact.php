<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Entities;

use DateTimeImmutable;
use Modules\Achievements\Domain\ValueObjects\FactAttribute;

/**
 * A row of the ledger: one person's part in one subject of one source. `kind` is the source's
 * kind (a MetricKinds value), `occurredAt` an instant in the storage zone and `placement` the
 * person's place where the subject has a ranking (1 = the winner).
 */
final readonly class AchievementFact
{
    /**
     * @param  array<string, FactAttribute>  $attributes  attribute key => value
     */
    public function __construct(
        public string $sourceType,
        public string $subjectId,
        public string $userId,
        public string $role,
        public string $kind,
        public DateTimeImmutable $occurredAt,
        public array $attributes = [],
        public ?int $placement = null,
    ) {}
}
