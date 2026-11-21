<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use Modules\Achievements\Application\Enums\RecalculationOutcome;

/**
 * What recalculating one source did: subjects read from its history, how many of them changed in
 * the ledger and how many stored subjects were removed because the history no longer lists them.
 */
final readonly class SourceRecalculationDTO
{
    public function __construct(
        public string $sourceType,
        public RecalculationOutcome $outcome,
        public int $subjects = 0,
        public int $changed = 0,
        public int $removed = 0,
    ) {}

    public function isComplete(): bool
    {
        return $this->outcome === RecalculationOutcome::Complete;
    }
}
