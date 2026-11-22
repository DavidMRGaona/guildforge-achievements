<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use Modules\Achievements\Application\Services\CommunityMetricsInterface;
use Modules\Achievements\Domain\Events\AchievementFactsChanged;

/**
 * The accounts of a refreshed subject may have met somebody new: their cached community progress
 * is counted again the next time it is shown. Runs at once (a few cache keys) and never throws.
 */
final readonly class ForgetCommunityProgress
{
    public function __construct(
        private CommunityMetricsInterface $community,
    ) {}

    public function handle(AchievementFactsChanged $event): void
    {
        $this->community->forgetProgress($event->userIds);
    }
}
