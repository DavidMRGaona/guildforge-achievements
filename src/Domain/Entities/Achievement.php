<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Entities;

use Modules\Achievements\Domain\Rules\AchievementRule;

/**
 * An achievement of the catalogue. `rule` is null when the stored rule cannot be read: such an
 * achievement is never evaluated. `icon` is one of the core's ProfileBadgeIcons::NAMES and
 * `colour` a ProfileBadgeColour value, kept as text so a value the core drops later never breaks
 * the read (the core checks both again when it draws a badge).
 */
final readonly class Achievement
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $icon,
        public string $colour,
        public ?string $imagePublicId,
        public int $points,
        public bool $secret,
        public bool $active,
        public ?AchievementRule $rule,
        public ?string $starterKey = null,
    ) {}
}
