<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use Modules\Achievements\Domain\Events\AchievementGranted;
use Modules\Achievements\Jobs\RefreshAchievementRarityJob;

/**
 * A new holder changes the rarity of what they got: computed again in the queue (one job however
 * many grants arrive while it waits).
 */
final class RefreshRarityAfterGrant
{
    public function handle(AchievementGranted $event): void
    {
        RefreshAchievementRarityJob::dispatch();
    }
}
