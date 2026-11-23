<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Points;

use DateTimeImmutable;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Application\Services\PointsCutoffInterface;
use Throwable;

/**
 * The cut is the instant of the nightly photo (#28): the same one the profile, the count and the
 * featured badges follow, so what others see of a person's list and of their points always agrees
 * and nothing can be worked out by subtracting one from the other. No photo yet, or one that cannot
 * be read, gives no cut: others see no points.
 */
final readonly class NightlyPhotoPointsCutoff implements PointsCutoffInterface
{
    public function __construct(
        private AchievementRarityInterface $rarity,
    ) {}

    public function takenAt(): ?DateTimeImmutable
    {
        try {
            return $this->rarity->current()->takenAt;
        } catch (Throwable) {
            return null;
        }
    }
}
