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
 * says to try again. A grant is announced only when the challenge was completed (or the person
 * added to it) within the last days and the evaluation was not silent; a recalculation never is.
 *
 * @phpstan-impure
 */
interface ChallengeEvaluatorInterface
{
    /**
     * @param  list<string>|null  $challengeIds  only these; null for every challenge still counted
     */
    public function evaluate(?array $challengeIds, bool $silent): ChallengeEvaluationResultDTO;
}
