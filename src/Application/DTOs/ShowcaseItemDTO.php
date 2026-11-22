<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use DateTimeImmutable;

/**
 * One achievement on a profile, as the person viewing may see it.
 *
 * Earned: `grantedAt` is when the owner got it (the latest season of one that repeats); `rarity` its
 * share among active people (null when rarity is off or it is shown masked); `featured` whether it
 * is one of the owner's featured badges; `seasons` the seasons it was got in, newest first (empty
 * for one that does not repeat): years only, never what was played in them.
 * Locked (only ever built for the owner): `progress` and `threshold` say how far they are («7 de
 * 10»), both null for an achievement granted by hand or shown masked; `season` is the season that
 * progress is about (one that repeats) and `inMonths` says it counts months in a row (a streak).
 */
final readonly class ShowcaseItemDTO
{
    /**
     * @param  list<string>  $seasons
     */
    public function __construct(
        public AchievementViewDTO $achievement,
        public ?DateTimeImmutable $grantedAt = null,
        public ?float $rarity = null,
        public bool $featured = false,
        public ?int $progress = null,
        public ?int $threshold = null,
        public bool $manual = false,
        public array $seasons = [],
        public ?string $season = null,
        public bool $inMonths = false,
    ) {}
}
