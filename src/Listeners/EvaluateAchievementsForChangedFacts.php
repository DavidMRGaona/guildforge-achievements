<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Achievements\Application\Services\AchievementEvaluatorInterface;
use Modules\Achievements\Domain\Events\AchievementFactsChanged;

/**
 * The ledger changed for some accounts: their achievements are evaluated again, on the queue, after
 * the change commits. Silent when the change came from a recalculation.
 *
 * Settings that cannot be read grant nothing; the job is tried again later, since this change would
 * otherwise wait for the next one of these accounts.
 */
final class EvaluateAchievementsForChangedFacts implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    private const int LAST_BACKOFF = 300;

    public function __construct(
        private readonly AchievementEvaluatorInterface $evaluator,
    ) {}

    public function handle(AchievementFactsChanged $event): void
    {
        $result = $this->evaluator->evaluate($event->userIds, $event->silent);

        if (! $result->isEvaluated() && $this->job !== null && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? self::LAST_BACKOFF);
        }
    }
}
