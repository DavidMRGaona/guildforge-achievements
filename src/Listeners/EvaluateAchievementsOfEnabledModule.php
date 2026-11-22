<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use App\Domain\Modules\Events\ModuleEnabled;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;

/**
 * A module enabled (again) may bring the source of achievements that had none: they are evaluated
 * for everybody from the ledger, silently. The job runs on a worker that already loads the module
 * (the core restarts workers after a change of the modules) and looks only at the kinds its sources
 * offer.
 */
final class EvaluateAchievementsOfEnabledModule
{
    public function handle(ModuleEnabled $event): void
    {
        if ($event->moduleName === 'achievements') {
            return;
        }

        EvaluateAchievementsJob::dispatch(module: $event->moduleName);
    }
}
