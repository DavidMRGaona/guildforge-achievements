<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

use DateTimeImmutable;
use Modules\Achievements\Domain\ValueObjects\MentorDecision;

/**
 * The mentor decided for each newcomer (`achievements_mentor_decisions`): written once by the
 * nightly batch and never replaced, so a newcomer has at most one mentor whatever their facts say
 * later. Account ids in lower case. Only counted, never shown.
 */
interface MentorDecisionRepositoryInterface
{
    /**
     * Accounts with a fact of `$kind` in `$role` (a game played) and no decision yet, sorted, after
     * `$afterUserId`, at most `$limit`. Accounts that are anonymized or no longer exist never are.
     *
     * @return list<string>
     */
    public function undecidedNewcomers(string $kind, string $role, ?string $afterUserId, int $limit): array;

    /**
     * Writes the decisions of newcomers not decided yet; one already decided is left as it was.
     * Answers how many were written.
     *
     * @param  list<MentorDecision>  $decisions
     */
    public function record(array $decisions): int;

    /**
     * For each of these accounts, when each newcomer it was the mentor of was decided, oldest first;
     * accounts with none are missing. Newcomers anonymized or no longer existing never count.
     *
     * @param  list<string>  $mentorIds
     * @return array<string, list<DateTimeImmutable>>
     */
    public function welcomed(array $mentorIds): array;

    /**
     * An anonymized account: its own decision as a newcomer is erased (it is never decided again,
     * since anonymized accounts are never listed), and wherever it was the mentor the newcomer
     * stays decided, with nobody.
     */
    public function forgetAccount(string $userId): void;
}
