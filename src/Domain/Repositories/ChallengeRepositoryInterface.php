<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

use DateTimeImmutable;
use Modules\Achievements\Domain\Challenges\ChallengeProgress;
use Modules\Achievements\Domain\Entities\Challenge;

/**
 * The collective challenges (#12) and who added to each. Days are `Y-m-d` of the display zone;
 * account ids are compared in lower case.
 */
interface ChallengeRepositoryInterface
{
    public function find(string $id): ?Challenge;

    /**
     * Every challenge, the latest to start first.
     *
     * @return list<Challenge>
     */
    public function all(): array;

    /**
     * The challenges whose total is worked out again on `$today` (Challenge::isCounted()), oldest
     * first.
     *
     * @param  list<string>|null  $ids  only these; null for every one
     * @return list<Challenge>
     */
    public function counted(string $today, ?array $ids = null): array;

    /**
     * What the site shows on `$today`: those not closed (or closed once complete) that start within
     * `$aheadDays` or ended at most `$recentDays` ago, the soonest to end first.
     *
     * @return list<Challenge>
     */
    public function shown(string $today, int $aheadDays, int $recentDays): array;

    /**
     * Stores a new count, under the challenge's row lock: the total, the number of people and each
     * person's contribution (rows of people who no longer add are removed). The completion is only
     * ever set, never cleared: a total that later drops keeps the challenge complete. Null (nothing
     * written) when the challenge is gone or was closed meanwhile.
     */
    public function saveProgress(string $id, ChallengeProgress $progress, DateTimeImmutable $countedAt): ?Challenge;

    /**
     * Closes it by hand; false when it was already closed or is gone.
     */
    public function close(string $id, ?string $actorId, DateTimeImmutable $at): bool;

    /**
     * What one person added to each of these challenges (0 when nothing).
     *
     * @param  list<string>  $challengeIds
     * @return array<string, int>
     */
    public function contributionsOf(string $userId, array $challengeIds): array;

    /**
     * Who added to a challenge, sorted by id.
     *
     * @return list<string>
     */
    public function contributorIds(string $challengeId): array;

    /**
     * The ids of the challenges whose reward is this achievement.
     *
     * @return list<string>
     */
    public function rewarding(string $achievementId): array;

    /**
     * Deletes every contribution of an account (anonymization) and returns how many there were.
     */
    public function forgetUser(string $userId): int;
}
