<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\ValueObjects\NewGrant;

/**
 * Who holds what (`achievements_grants`), unique per account, achievement and season. Rows are only
 * added here, never revoked by an evaluation; deleting is for anonymization (and, later, a revoke
 * by hand with its reason).
 */
interface AchievementGrantRepositoryInterface
{
    /**
     * Writes the grant unless it is already held. Null when it was held (also when a concurrent
     * writer got there first), when the achievement no longer exists, and when the account does not
     * exist or is anonymized: the account is read with a shared lock, so an anonymization running
     * meanwhile waits for this write and then deletes it.
     */
    public function grant(NewGrant $grant): ?AchievementGrant;

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

    /**
     * Every grant of each account asked, oldest first, in one query per 500 accounts, keyed by
     * account id in lower case; accounts holding nothing are missing.
     *
     * @param  list<string>  $userIds
     * @return array<string, list<AchievementGrant>>
     */
    public function forUsers(array $userIds): array;

    public function holds(string $userId, string $achievementId): bool;

    /**
     * How many accounts hold each achievement, keyed by achievement id (achievements nobody holds
     * are missing).
     *
     * @return array<string, int>
     */
    public function holderCounts(): array;

    /**
     * Deletes every grant of an account (anonymization) and returns how many there were.
     */
    public function deleteForUser(string $userId): int;
}
