<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Observers;

use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;

/**
 * An achievement created, or one whose rule, switch or secrecy changed, is evaluated for everybody
 * from the ledger, silently and on the queue: whoever already met it gets it without a notice, as
 * `achievements:recalculate --achievement=` would do.
 */
final class EvaluateChangedAchievements
{
    private const array EVALUATED_ATTRIBUTES = ['rule', 'is_active', 'is_secret'];

    public function created(AchievementModel $achievement): void
    {
        if ($achievement->is_active) {
            EvaluateAchievementsJob::dispatch([$achievement->id]);
        }
    }

    /**
     * "updated", not "saved": it fires only after a real update, with that update's changes.
     */
    public function updated(AchievementModel $achievement): void
    {
        if ($achievement->is_active && $achievement->wasChanged(self::EVALUATED_ATTRIBUTES)) {
            EvaluateAchievementsJob::dispatch([$achievement->id]);
        }
    }
}
