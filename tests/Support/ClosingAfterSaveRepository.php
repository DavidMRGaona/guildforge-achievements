<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use Closure;
use DateTimeImmutable;
use Modules\Achievements\Domain\Challenges\ChallengeProgress;
use Modules\Achievements\Domain\Entities\Challenge;
use Modules\Achievements\Domain\Repositories\ChallengeRepositoryInterface;

/**
 * The real challenges repository, closing a challenge by hand right after a count saved it and
 * before its rewards are granted: what a person closing it from the panel at that instant does.
 */
final readonly class ClosingAfterSaveRepository implements ChallengeRepositoryInterface
{
    public function __construct(
        private ChallengeRepositoryInterface $inner,
    ) {}

    public function saveProgress(string $id, ChallengeProgress $progress, DateTimeImmutable $countedAt, int $loadedVersion): ?Challenge
    {
        $saved = $this->inner->saveProgress($id, $progress, $countedAt, $loadedVersion);

        if ($saved instanceof Challenge) {
            $this->inner->close($id, null, $countedAt);
        }

        return $saved;
    }

    public function find(string $id): ?Challenge
    {
        return $this->inner->find($id);
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function counted(string $today, ?array $ids = null): array
    {
        return $this->inner->counted($today, $ids);
    }

    public function shown(string $today, int $aheadDays, int $recentDays): array
    {
        return $this->inner->shown($today, $aheadDays, $recentDays);
    }

    public function publishShown(DateTimeImmutable $at): int
    {
        return $this->inner->publishShown($at);
    }

    public function whileOpen(string $id, Closure $work): bool
    {
        return $this->inner->whileOpen($id, $work);
    }

    public function deleteUnlessCompleted(string $id): bool
    {
        return $this->inner->deleteUnlessCompleted($id);
    }

    public function rewardingCounted(string $achievementId, string $today): array
    {
        return $this->inner->rewardingCounted($achievementId, $today);
    }

    public function audienceOf(string $challengeId): array
    {
        return $this->inner->audienceOf($challengeId);
    }

    public function rememberAudience(string $challengeId, array $audience, bool $replace, DateTimeImmutable $at): void
    {
        $this->inner->rememberAudience($challengeId, $audience, $replace, $at);
    }

    public function close(string $id, ?string $actorId, DateTimeImmutable $at): bool
    {
        return $this->inner->close($id, $actorId, $at);
    }

    public function contributionsOf(string $userId, array $challengeIds): array
    {
        return $this->inner->contributionsOf($userId, $challengeIds);
    }

    public function contributorIds(string $challengeId): array
    {
        return $this->inner->contributorIds($challengeId);
    }

    public function completed(): array
    {
        return $this->inner->completed();
    }

    public function listed(string $challengeId): array
    {
        return $this->inner->listed($challengeId);
    }

    public function replaceListed(string $challengeId, array $userIds, DateTimeImmutable $at): void
    {
        $this->inner->replaceListed($challengeId, $userIds, $at);
    }

    public function rewarding(string $achievementId): array
    {
        return $this->inner->rewarding($achievementId);
    }

    public function forgetUser(string $userId): int
    {
        return $this->inner->forgetUser($userId);
    }
}
