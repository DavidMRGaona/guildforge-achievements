<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Events\AchievementFactsChanged;
use Modules\Achievements\Jobs\EvaluateChallengesJob;

/**
 * The ledger changed: the challenges are counted again, on the queue, after the change commits.
 * Silent when the change came from a recalculation. With the «Retos» switch off nothing is queued
 * (the job reads the settings strictly anyway).
 */
final readonly class CountChallengesForChangedFacts
{
    public function __construct(
        private AchievementsSettingsInterface $settings,
    ) {}

    public function handle(AchievementFactsChanged $event): void
    {
        if (! $this->settings->challengesEnabled()) {
            return;
        }

        EvaluateChallengesJob::dispatch(null, $event->silent);
    }
}
