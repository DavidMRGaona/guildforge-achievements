<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\ValueObjects\NewGrant;

/**
 * Who holds what (`achievements_grants`), unique per account, achievement and season, and what
 * happened to each grant (`achievements_grant_log`), written in the same transaction as the change.
 * Rows are never revoked by an evaluation: only revoke() (by hand, with its reason) and
 * anonymization take one away.
 */
interface AchievementGrantRepositoryInterface
{
    /**
     * Writes the grant unless it is already held. Null when it was held (also when a concurrent
     * writer got there first), when the achievement no longer exists, and when the account does not
     * exist or is anonymized: the account is read with a shared lock, so an anonymization running
     * meanwhile waits for this write and then deletes it. Also null when someone revoked it from
     * this account and the grant is not by hand: a revoked achievement never comes back by a rule,
     * a challenge or a bulk grant, only by a person granting it to that account again. A new grant
     * writes its history entry.
     */
    public function grant(NewGrant $grant): ?AchievementGrant;

    public function find(string $grantId): ?AchievementGrant;

    /**
     * Deletes the grant and writes its history entry (actor and reason), under a lock on the row.
     * Null when the grant no longer exists or the reason is blank (GrantReason).
     */
    public function revoke(string $grantId, string $actorId, string $reason): ?AchievementGrant;

    /**
     * The accounts among these whose latest history entry for the achievement (seasonless) is a
     * revoke: what grant() refuses for anything but a grant by hand. In lower case.
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function revokedAmong(string $achievementId, array $userIds): array;

    /**
     * For each of these accounts, the achievements (seasonless) whose latest history entry is a
     * revoke, keyed by account id in lower case; accounts with none are missing.
     *
     * @param  list<string>  $userIds
     * @return array<string, list<string>>
     */
    public function revokedAchievementIds(array $userIds): array;

    /**
     * Ids of the achievements each account holds (seasonless grants), keyed by account id in lower
     * case; accounts holding nothing are missing.
     *
     * @param  list<string>  $userIds
     * @return array<string, list<string>>
     */
    public function heldAchievementIds(array $userIds): array;

    /**
     * Every grant of an account, oldest first.
     *
     * @return list<AchievementGrant>
     */
    public function forUser(string $userId): array;

    public function holds(string $userId, string $achievementId): bool;

    /**
     * How many accounts hold each achievement, keyed by achievement id (achievements nobody holds
     * are missing).
     *
     * @return array<string, int>
     */
    public function holderCounts(): array;

    /**
     * Anonymization: deletes every grant of the account and returns how many there were. Its history
     * entries stay, without the account and without their reasons (written about the person), so
     * what others granted or revoked is still accounted for; entries where the account was the
     * actor stay as they are.
     */
    public function deleteForUser(string $userId): int;
}
