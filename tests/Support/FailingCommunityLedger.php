<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use Closure;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\FactsReplacement;
use RuntimeException;

/**
 * The real ledger, except that the community queries fail (a database that stops answering, say).
 */
final readonly class FailingCommunityLedger implements AchievementFactRepositoryInterface
{
    public function __construct(
        private AchievementFactRepositoryInterface $inner,
    ) {}

    public function withSubjectLock(string $sourceType, string $subjectId, Closure $work): mixed
    {
        return $this->inner->withSubjectLock($sourceType, $subjectId, $work);
    }

    public function replaceSubject(string $sourceType, string $subjectId, string $kind, array $facts): FactsReplacement
    {
        return $this->inner->replaceSubject($sourceType, $subjectId, $kind, $facts);
    }

    public function previewReplacement(string $sourceType, string $subjectId, string $kind, array $facts): FactsReplacement
    {
        return $this->inner->previewReplacement($sourceType, $subjectId, $kind, $facts);
    }

    public function subjectIds(string $sourceType): array
    {
        return $this->inner->subjectIds($sourceType);
    }

    public function recentSubjectIds(string $sourceType, int $limit): array
    {
        return $this->inner->recentSubjectIds($sourceType, $limit);
    }

    public function userIdsOfSubject(string $sourceType, string $subjectId): array
    {
        return $this->inner->userIdsOfSubject($sourceType, $subjectId);
    }

    public function forUser(string $userId, array $kinds = []): array
    {
        return $this->inner->forUser($userId, $kinds);
    }

    public function forUsers(array $userIds, array $kinds = []): array
    {
        return $this->inner->forUsers($userIds, $kinds);
    }

    public function userIdsWithKinds(array $kinds, ?string $afterUserId, int $limit): array
    {
        return $this->inner->userIdsWithKinds($kinds, $afterUserId, $limit);
    }

    public function sourceTypesOfKind(string $kind): array
    {
        return $this->inner->sourceTypesOfKind($kind);
    }

    public function countForUser(string $userId, string $kind, ?string $role = null): int
    {
        return $this->inner->countForUser($userId, $kind, $role);
    }

    public function firstMeetings(array $userIds, string $kind, ?string $role, ?string $withRole): array
    {
        throw new RuntimeException('The ledger cannot be read.');
    }

    public function newcomersWelcomed(array $userIds, string $kind, string $hostRole, string $newcomerRole): array
    {
        throw new RuntimeException('The ledger cannot be read.');
    }

    public function hostsOfFirstFacts(array $userIds, string $kind, string $hostRole, string $newcomerRole): array
    {
        throw new RuntimeException('The ledger cannot be read.');
    }

    public function userIdsSharingSubjectsWith(string $userId): array
    {
        return $this->inner->userIdsSharingSubjectsWith($userId);
    }

    public function deleteForUser(string $userId): int
    {
        return $this->inner->deleteForUser($userId);
    }
}
