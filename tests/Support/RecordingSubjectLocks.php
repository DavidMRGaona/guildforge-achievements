<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\FactsReplacement;

/**
 * The real ledger, remembering each subject lock taken and how many recognitions there were when it
 * was taken and when it was let go.
 */
final class RecordingSubjectLocks implements AchievementFactRepositoryInterface
{
    /** @var list<array{string, string}> */
    public array $locked = [];

    /** @var list<int> */
    public array $rowsWhenTaken = [];

    /** @var list<int> */
    public array $rowsWhenReleased = [];

    public function __construct(
        private readonly AchievementFactRepositoryInterface $inner,
    ) {}

    public function withSubjectLock(string $sourceType, string $subjectId, Closure $work): mixed
    {
        return $this->inner->withSubjectLock($sourceType, $subjectId, function () use ($sourceType, $subjectId, $work): mixed {
            $this->locked[] = [$sourceType, $subjectId];
            $this->rowsWhenTaken[] = DB::table('achievements_recognitions')->count();
            $result = $work();
            $this->rowsWhenReleased[] = DB::table('achievements_recognitions')->count();

            return $result;
        });
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

    public function forKindBetween(string $kind, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        return $this->inner->forKindBetween($kind, $from, $until);
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
        return $this->inner->firstMeetings($userIds, $kind, $role, $withRole);
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
