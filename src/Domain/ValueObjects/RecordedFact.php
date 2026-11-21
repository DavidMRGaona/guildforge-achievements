<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

use DateTimeImmutable;

/**
 * One person's part in one subject, as the ledger stores it: what a source reported in an
 * ActivityFactDTO, without the core's types. Roles stay strings, so a role of a newer core is
 * kept as it is.
 */
final readonly class RecordedFact
{
    /**
     * @param  array<string, FactAttribute>  $attributes  attribute key => value
     */
    public function __construct(
        public string $userId,
        public string $role,
        public DateTimeImmutable $occurredAt,
        public array $attributes = [],
        public ?int $placement = null,
    ) {}
}
