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

    private static bool $muted = false;

    /**
     * Runs `$work` without queuing an evaluation per achievement: whoever creates many at once
     * (the starter catalogue) queues one for all of them.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function muted(callable $work): mixed
    {
        $previous = self::$muted;
        self::$muted = true;

        try {
            return $work();
        } finally {
            self::$muted = $previous;
        }
    }

    public function created(AchievementModel $achievement): void
    {
        if ($achievement->is_active && ! self::$muted) {
            EvaluateAchievementsJob::dispatch([$achievement->id]);
        }
    }

    /**
     * "updated", not "saved": it fires only after a real update, with that update's changes.
     */
    public function updated(AchievementModel $achievement): void
    {
        if ($achievement->is_active && ! self::$muted && $achievement->wasChanged(self::EVALUATED_ATTRIBUTES)) {
            EvaluateAchievementsJob::dispatch([$achievement->id]);
        }
    }
}
