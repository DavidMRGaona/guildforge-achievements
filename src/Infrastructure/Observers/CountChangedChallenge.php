<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Observers;

use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\ChallengeModel;
use Modules\Achievements\Jobs\EvaluateChallengesJob;

/**
 * A challenge created, or whose rule, dates, audience or reward changed, is counted again at once
 * (queued after the save commits). The counts and the closing written by the module itself never
 * queue anything.
 */
final class CountChangedChallenge
{
    public function created(ChallengeModel $challenge): void
    {
        EvaluateChallengesJob::dispatch([$challenge->id], false);
    }

    public function updated(ChallengeModel $challenge): void
    {
        if ($challenge->closed_at === null && $challenge->wasChanged(ChallengeModel::COUNTED)) {
            EvaluateChallengesJob::dispatch([$challenge->id], false);
        }
    }
}
