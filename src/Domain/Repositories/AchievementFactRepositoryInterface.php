<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

use Closure;
use Modules\Achievements\Domain\Entities\AchievementFact;
use Modules\Achievements\Domain\ValueObjects\FactsReplacement;
use Modules\Achievements\Domain\ValueObjects\RecordedFact;

/**
 * The ledger (`achievements_facts`): a mirror, subject by subject, of what the activity sources
 * report. One row per source type, subject, person and role. Only the ledger service writes it,
 * and only with an answer a source gave (never because a source is missing or failed).
 *
 * Account ids are compared in lower case. Facts of deactivated accounts stay; whether they count
 * is decided where they are read (rarity, leaderboard).
 */
interface AchievementFactRepositoryInterface
{
    /**
     * Runs `$work` in a transaction holding the subject's lock, so the reads and writes of one
     * subject never interleave with another refresh of it. Retried on a deadlock (3 attempts).
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function withSubjectLock(string $sourceType, string $subjectId, Closure $work): mixed;

    /**
     * Makes the subject's rows exactly `$facts` (none removes the subject), in one transaction. Rows
     * that did not change are left as they are, so replacing with the same facts writes nothing.
     * Facts of accounts anonymized meanwhile are dropped. Callers hold the subject's lock
     * (withSubjectLock()), or two writers of one subject could interleave.
     *
     * @param  string  $kind  the source's kind (a MetricKinds value)
     * @param  list<RecordedFact>  $facts  one per person and role
     */
    public function replaceSubject(string $sourceType, string $subjectId, string $kind, array $facts): FactsReplacement;

    /**
     * What replaceSubject() would report, without writing anything (dry runs).
     *
     * @param  list<RecordedFact>  $facts
     */
    public function previewReplacement(string $sourceType, string $subjectId, string $kind, array $facts): FactsReplacement;

    /**
     * Ids of the subjects of a source type that have facts, sorted.
     *
     * @return list<string>
     */
    public function subjectIds(string $sourceType): array;

    /**
     * The accounts with facts in a subject, sorted.
     *
     * @return list<string>
     */
    public function userIdsOfSubject(string $sourceType, string $subjectId): array;

    /**
     * Every fact of an account, oldest first, optionally only of some kinds.
     *
     * @param  list<string>  $kinds  MetricKinds values; empty for every kind
     * @return list<AchievementFact>
     */
    public function forUser(string $userId, array $kinds = []): array;

    /**
     * The facts of several accounts at once, oldest first, keyed by account id in lower case. An
     * account with no facts is missing from the answer.
     *
     * @param  list<string>  $userIds
     * @param  list<string>  $kinds  MetricKinds values; empty for every kind
     * @return array<string, list<AchievementFact>>
     */
    public function forUsers(array $userIds, array $kinds = []): array;

    /**
     * Accounts with at least one fact of these kinds, sorted, after `$afterUserId` (keyset pages).
     *
     * @param  list<string>  $kinds  MetricKinds values; never empty
     * @return list<string>
     */
    public function userIdsWithKinds(array $kinds, ?string $afterUserId, int $limit): array;

    /**
     * How many facts of a kind an account has, optionally in one role (an ActivityRole value).
     */
    public function countForUser(string $userId, string $kind, ?string $role = null): int;

    /**
     * Deletes every fact of an account (anonymization) and returns how many there were.
     */
    public function deleteForUser(string $userId): int;
}
