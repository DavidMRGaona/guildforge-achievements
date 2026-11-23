<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use App\Modules\ModuleLoader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Achievements\Application\Exceptions\AchievementSettingsUnreadableException;
use Modules\Achievements\Application\Services\AchievementEvaluatorInterface;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;

/**
 * Evaluates achievements for everybody with facts in the ledger, without reading the sources again:
 *
 * - after achievements are created or their rule changes, and after a kind or the secret
 *   achievements are switched on again (silent);
 * - after a module is enabled, for the achievements of the kinds its sources offer (silent);
 * - every night for the years of account, which change with the date alone (not silent: only the
 *   anniversaries of the last days are announced, AnnouncementWindow);
 * - every night for the rules left to the nightly batch («Mentor», `onlyNightly`; not silent: only
 *   the newcomers decided in the last days are announced).
 *
 * Unique only while it waits in the queue (same achievements, module, silence and scope): the lock
 * goes when it starts, since it reads the rules and the settings then. A dispatch made while it runs
 * or waits after a release() is queued again and reads what changed meanwhile (two edits of a rule
 * in a row are both evaluated).
 *
 * Settings that cannot be read are tried again and, after the last try, fail the job (failed_jobs).
 * A kind whose source is registered but unavailable right now is tried again later; after the last
 * try the job ends, and enabling the module again or a recalculation evaluates it. A module this
 * worker has not loaded yet (an old worker took the job before restarting) is waited for too.
 */
final class EvaluateAchievementsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    /** A walk of the whole ledger, in chunks of 200 accounts */
    public int $timeout = 900;

    /** The lock is dropped after an hour even if a worker died holding it */
    public int $uniqueFor = 3600;

    private const int LAST_BACKOFF = 900;

    /**
     * @param  list<string>|null  $achievementIds  null for every achievement
     * @param  string|null  $module  a module just enabled: only the kinds its sources offer
     * @param  bool  $onlyNightly  the nightly batch: only the rules left to it (MetricType::isNightly())
     */
    public function __construct(
        public readonly ?array $achievementIds = null,
        public readonly bool $silent = true,
        public readonly bool $onlyAccountYears = false,
        public readonly ?string $module = null,
        public readonly bool $onlyNightly = false,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        $ids = $this->achievementIds;

        if ($ids !== null) {
            $ids = array_map(strtolower(...), $ids);
            sort($ids, SORT_STRING);
        }

        return sha1((string) json_encode([$ids, $this->silent, $this->onlyAccountYears, $this->module, $this->onlyNightly]));
    }

    public function handle(AchievementEvaluatorInterface $evaluator, ActivityMetricsInterface $metrics, ModuleLoader $modules): void
    {
        $kinds = null;

        if ($this->module !== null) {
            // A worker that booted before the module was enabled does not know its sources yet
            if (! $modules->isLoaded($this->module)) {
                $this->retryLater('module_not_loaded');

                return;
            }

            $kinds = $metrics->kindsOfModule($this->module);

            // A module without activity sources (or none offered now): nothing to evaluate
            if ($kinds === []) {
                return;
            }
        }

        $result = $evaluator->evaluateEverybody($this->achievementIds, $this->silent, onlyAccountYears: $this->onlyAccountYears, onlyKinds: $kinds, onlyNightly: $this->onlyNightly);

        if (! $result->isEvaluated()) {
            if ($this->job !== null && $this->attempts() >= $this->tries) {
                $this->fail(AchievementSettingsUnreadableException::afterRetries());

                return;
            }

            $this->retryLater('settings_unreadable');

            return;
        }

        if ($result->pendingKinds !== []) {
            $this->retryLater('source_unavailable');
        }
    }

    private function retryLater(string $reason): void
    {
        if ($this->job === null) {
            return;
        }

        if ($this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? self::LAST_BACKOFF);

            return;
        }

        Log::warning('[Achievements] An evaluation gave up waiting; it runs again when the module is enabled or on a recalculation.', [
            'reason' => $reason,
            'module' => $this->module,
        ]);
    }
}
