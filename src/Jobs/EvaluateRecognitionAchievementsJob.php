<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\MetricType;

/**
 * On the 1st of each month, just after midnight in the display zone (#11): the recognitions of the
 * month before start counting, so the achievements that count them are evaluated for everybody,
 * not silently. Between two runs they wait, whatever else evaluates them (the rule counts only up
 * to the start of the month).
 */
final class EvaluateRecognitionAchievementsJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public const string RUN_AT = '00:30';

    public function handle(AchievementRepositoryInterface $achievements): void
    {
        $ids = array_values(array_map(
            static fn (Achievement $achievement): string => $achievement->id,
            array_filter(
                $achievements->active(),
                static fn (Achievement $achievement): bool => $achievement->rule instanceof AchievementRule && $achievement->rule->metric === MetricType::Recognitions,
            ),
        ));

        if ($ids !== []) {
            EvaluateAchievementsJob::dispatch($ids, false);
        }
    }
}
