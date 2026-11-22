<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Modules\Achievements\Application\DTOs\AchievementRarityDTO;

/**
 * The rarity of every achievement, computed every night and after each grant, and kept in the cache
 * in between. Reading never throws: a cache or database that cannot be reached gives
 * AchievementRarityDTO::unknown(), and pages then show no rarity.
 */
interface AchievementRarityInterface
{
    /**
     * As last computed; computed now (and kept) when the cache holds nothing.
     */
    public function current(): AchievementRarityDTO;

    /**
     * Computes it again from the ledger and the grants and keeps it. Throws when it cannot be
     * computed: the job that calls it is tried again.
     */
    public function refresh(): AchievementRarityDTO;
}
