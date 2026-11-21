<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

/**
 * One attribute value of a fact, as its source gave it: a stable id that rules compare («3
 * different systems») and the label shown to people.
 */
final readonly class FactAttribute
{
    public function __construct(
        public string $id,
        public string $label,
    ) {}
}
