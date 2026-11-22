<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use Modules\Achievements\Domain\Events\AchievementGranted;
use Modules\Achievements\Domain\Events\AchievementRevoked;
use Modules\Achievements\Jobs\RefreshAchievementRarityJob;

/**
 * A holder more or less changes the rarity of that achievement: a grant, or a revoke by hand.
 * Computed again in the queue (one job however many grants arrive while it waits).
 */
final class RefreshRarityAfterGrant
{
    public function handle(AchievementGranted|AchievementRevoked $event): void
    {
        RefreshAchievementRarityJob::dispatch();
    }
}
