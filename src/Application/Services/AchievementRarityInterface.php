<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Modules\Achievements\Application\DTOs\AchievementRarityDTO;

/**
 * The nightly photo: the rarity of every achievement and the instant it was taken, which also
 * decides what anybody but each owner sees of their achievements (grants up to that instant). Taken
 * once a night, never on a grant or a revoke, so totals others see never rise at the moment and two
 * people who play together never give themselves away. Reading never throws and never computes: no
 * photo, or one that cannot be read, gives AchievementRarityDTO::unknown() (no rarity, `takenAt`
 * null, so others see nothing new).
 */
interface AchievementRarityInterface
{
    /**
     * The last photo, or unknown() while there is none.
     */
    public function current(): AchievementRarityDTO;

    /**
     * Takes the photo now from the ledger and the grants and keeps it. Only the nightly job calls
     * it. Throws when it cannot be taken: the job is tried again.
     */
    public function refresh(): AchievementRarityDTO;
}
