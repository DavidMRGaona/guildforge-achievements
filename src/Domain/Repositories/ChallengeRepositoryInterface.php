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
     * written) when the challenge is gone, was closed meanwhile, or was saved from the panel since
     * it was loaded (`$loadedUpdatedAt` differs: the count is of a rule that no longer exists). A
     * count never changes `updated_at`.
     */
    public function saveProgress(string $id, ChallengeProgress $progress, DateTimeImmutable $countedAt, ?DateTimeImmutable $loadedUpdatedAt): ?Challenge;

    /**
     * Deletes a challenge that never completed, deciding under its row lock; false otherwise.
     */
    public function deleteUnlessCompleted(string $id): bool;

    /**
     * The challenges still counted on `$today` (not closed, not past the late facts' days) whose
     * reward is this achievement.
     *
     * @return list<string>
     */
    public function rewardingCounted(string $achievementId, string $today): array;

    /**
     * Who counted as an association member for a challenge of members, as remembered.
     *
     * @return array<string, bool> account id (lower case) => member
     */
    public function audienceOf(string $challengeId): array;

    /**
     * Remembers who counts as a member. `$replace` forgets what was remembered first (within the
     * days); without it, people already known keep their answer (frozen once the days are over).
     *
     * @param  array<string, bool>  $audience
     */
    public function rememberAudience(string $challengeId, array $audience, bool $replace, DateTimeImmutable $at): void;

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
     * Deletes every contribution and remembered membership of an account (anonymization), forgets
     * it as the one who created or closed a challenge, and returns how many contributions there were.
     */
    public function forgetUser(string $userId): int;
}
