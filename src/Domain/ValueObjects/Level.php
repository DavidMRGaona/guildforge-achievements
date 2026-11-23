<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

/**
 * One step of the level scale: its number (1 for the first), its name and the points it starts at.
 */
final readonly class Level
{
    public function __construct(
        public int $number,
        public string $name,
        public int $threshold,
    ) {}
}
