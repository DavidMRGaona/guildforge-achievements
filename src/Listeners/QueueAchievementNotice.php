<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Achievements\Domain\Events\AchievementGranted;
use Modules\Achievements\Domain\Repositories\PendingAchievementNoticeRepositoryInterface;
use Modules\Achievements\Jobs\DeliverAchievementNoticesJob;

/**
 * A new grant is told a little later, together with the rest of its holder's (achievements#6). A
 * silent one (a recalculation, an old achievement, a bulk grant marked silent) is told to nobody.
 */
final class QueueAchievementNotice implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        private readonly PendingAchievementNoticeRepositoryInterface $pending,
    ) {}

    public function handle(AchievementGranted $event): void
    {
        if ($event->silent) {
            return;
        }

        $this->pending->add($event->grantId);

        DeliverAchievementNoticesJob::dispatch($event->userId)->delay(now()->addSeconds(DeliverAchievementNoticesJob::DELAY_SECONDS));
    }
}
