<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use Modules\Achievements\Domain\ValueObjects\Level;

/**
 * One account's points as one viewer sees them (#10): all time and, while seasons are on, in the
 * current season; with the level they reach and the next one while levels show. Totals only.
 */
final readonly class PointsSummaryDTO
{
    public function __construct(
        public int $total,
        public ?int $season,
        public int $seasonYear,
        public ?Level $level,
        public ?Level $nextLevel,
    ) {}
}
