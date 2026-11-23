<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use DateTimeImmutable;
use Modules\Achievements\Domain\Rules\AchievementRule;

/**
 * The people community rules count (`people`, `mentor`), from aggregate queries over the ledger,
 * never one account at a time. Only numbers and instants ever leave it: never who those people are.
 *
 * Anonymized accounts never count (the activity reader drops their facts and the ledger forgets
 * them); accounts that hide their achievements still count, since nobody learns who they are.
 */
interface CommunityMetricsInterface
{
    /**
     * For each account asked, one instant per different person the rule counts (when they were
     * first met; for `mentor`, when the newcomer's first game was decided, as the nightly batch
     * recorded it: MentorDecisionsInterface), oldest first; accounts with nobody
     * are missing. Read fresh from the ledger, for granting: it throws when the ledger cannot be
     * read, so nothing is granted on a guess. Empty for a rule that is not a community rule.
     *
     * @param  list<string>  $userIds
     * @return array<string, list<DateTimeImmutable>>
     */
    public function people(AchievementRule $rule, array $userIds): array;

    /**
     * How many people the rule counts for one account, for showing progress. Kept in the cache for a
     * few minutes and forgotten when the account's facts change. Null when it cannot be read: never
     * a misleading 0. Never throws.
     */
    public function progress(AchievementRule $rule, string $userId): ?int;

    /**
     * Forgets the cached progress of these accounts for every community rule of the catalogue. Never
     * throws.
     *
     * @param  list<string>  $userIds
     */
    public function forgetProgress(array $userIds): void;
}
