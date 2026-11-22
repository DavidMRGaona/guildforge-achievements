<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Achievements\Application\Exceptions\AchievementSettingsUnreadableException;
use Modules\Achievements\Application\Services\ChallengeEvaluatorInterface;

/**
 * Counts the collective challenges again (#12): after the ledger changes, after a challenge is saved,
 * after the «Retos» switch goes on and every night (who is an association member changes without any
 * fact changing).
 *
 * Unique only while it waits in the queue (same challenges and silence): the lock goes when it
 * starts, so a change made while it runs is counted by the next one. Many ledger changes in a row
 * make one count, not one each.
 *
 * Settings that cannot be read, or members that cannot be read for a challenge of members, are tried
 * again; after the last try unreadable settings fail the job (failed_jobs), and members are left for
 * the next count.
 */
final class EvaluateChallengesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 600;

    /** The lock is dropped after an hour even if a worker died holding it */
    public int $uniqueFor = 3600;

    private const int LAST_BACKOFF = 900;

    /**
     * @param  list<string>|null  $challengeIds  null for every challenge still counted
     */
    public function __construct(
        public readonly ?array $challengeIds = null,
        public readonly bool $silent = false,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        $ids = $this->challengeIds;

        if ($ids !== null) {
            $ids = array_map(strtolower(...), $ids);
            sort($ids, SORT_STRING);
        }

        return 'challenges:'.sha1((string) json_encode([$ids, $this->silent]));
    }

    public function handle(ChallengeEvaluatorInterface $evaluator): void
    {
        $result = $evaluator->evaluate($this->challengeIds, $this->silent);

        if (! $result->shouldRetry() || $this->job === null) {
            return;
        }

        if ($this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? self::LAST_BACKOFF);

            return;
        }

        if (! $result->isEvaluated()) {
            $this->fail(AchievementSettingsUnreadableException::afterRetries());

            return;
        }

        Log::warning('[Achievements] Some challenges for members could not be counted; the next count tries again.', [
            'challenge_ids' => $result->retry,
            'reason' => 'members_unavailable',
        ]);
    }
}
