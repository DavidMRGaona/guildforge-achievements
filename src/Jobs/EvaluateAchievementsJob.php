<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Achievements\Application\Services\AchievementEvaluatorInterface;

/**
 * Evaluates achievements for everybody with facts in the ledger, without reading the sources again:
 * after an achievement is created or its rule changes, after a kind or the secret achievements are
 * switched on again (silent), and every day for the years of account, which change with the date
 * alone (not silent: those are real anniversaries).
 */
final class EvaluateAchievementsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    private const int LAST_BACKOFF = 300;

    /**
     * @param  list<string>|null  $achievementIds  null for every achievement
     */
    public function __construct(
        public readonly ?array $achievementIds = null,
        public readonly bool $silent = true,
        public readonly bool $onlyAccountYears = false,
    ) {
        $this->afterCommit();
    }

    public function handle(AchievementEvaluatorInterface $evaluator): void
    {
        $result = $evaluator->evaluateEverybody($this->achievementIds, $this->silent, onlyAccountYears: $this->onlyAccountYears);

        if (! $result->isEvaluated() && $this->job !== null && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? self::LAST_BACKOFF);
        }
    }
}
