<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use DateTimeImmutable;

/**
 * One achievement on a profile, as the person viewing may see it.
 *
 * Earned: `grantedAt` is when the owner got it; `rarity` its share among active people (null when
 * rarity is off or it is shown masked); `featured` whether it is one of the owner's featured badges.
 * Locked (only ever built for the owner): `progress` and `threshold` say how far they are («7 de
 * 10»), both null for an achievement granted by hand or shown masked.
 * `points`: what the achievement gives (#10), its real value even when shown masked, so secrets the
 * viewer may not see can be added up as one group; never sent one by one for a masked item.
 */
final readonly class ShowcaseItemDTO
{
    public function __construct(
        public AchievementViewDTO $achievement,
        public ?DateTimeImmutable $grantedAt = null,
        public ?float $rarity = null,
        public bool $featured = false,
        public ?int $progress = null,
        public ?int $threshold = null,
        public bool $manual = false,
        public int $points = 0,
    ) {}
}
