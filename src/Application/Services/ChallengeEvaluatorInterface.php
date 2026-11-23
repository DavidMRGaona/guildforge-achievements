<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Modules\Achievements\Application\DTOs\ChallengeEvaluationResultDTO;

/**
 * Counts the collective challenges again from the ledger, stores each total and, once a challenge
 * is complete, grants its reward (origin `challenge`) to everybody who added to it.
 *
 * Nothing is counted with the «Retos» switch off, settings that cannot be read, a kind switched off
 * or without a source, or a rule that cannot be read; the last total stays as it was. A challenge
 * for association members whose membership cannot be read is left as it was too, and the result
 * says to try again. Who is a member is frozen once its days are over. Rewards are granted only by
 * counts that are not silent, and announced: a silent count (a recalculation) stores the total and
 * the completion, and the next count with notices grants. A count of a challenge saved from the
 * panel meanwhile is discarded.
 *
 * @phpstan-impure
 */
interface ChallengeEvaluatorInterface
{
    /**
     * @param  list<string>|null  $challengeIds  only these; null for every challenge still counted
     * @param  bool  $publish  the nightly count: once counted, the totals become what others see of a
     *                         challenge of members (ChallengeRepositoryInterface::publishShown())
     */
    public function evaluate(?array $challengeIds, bool $silent, bool $publish = false): ChallengeEvaluationResultDTO;
}
