<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use Modules\Achievements\Application\DTOs\AchievementRarityDTO;
use Modules\Achievements\Application\Services\AchievementRarityInterface;

/**
 * Rarity as a test sets it: what the ledger would say is not computed, and a grant (which asks for
 * it again) changes nothing.
 */
final readonly class FixedAchievementRarity implements AchievementRarityInterface
{
    public function __construct(
        private AchievementRarityDTO $rarity,
    ) {}

    public function current(): AchievementRarityDTO
    {
        return $this->rarity;
    }

    public function refresh(): AchievementRarityDTO
    {
        return $this->rarity;
    }
}
