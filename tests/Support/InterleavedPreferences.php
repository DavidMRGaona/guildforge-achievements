<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use Closure;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\AchievementPreferences;

/**
 * Another writer saving at the worst moment: the first time this request reads or writes the
 * choices, the other write runs first and the read answers what was there before it (a stale read).
 * A writer that read, changed and saved every column would undo the other write.
 */
final class InterleavedPreferences implements AchievementPreferencesRepositoryInterface
{
    private bool $interleaved = false;

    public function __construct(
        private readonly AchievementPreferencesRepositoryInterface $real,
        private readonly AchievementPreferences $stale,
        private readonly Closure $otherWrite,
    ) {}

    public function forUsers(array $userIds): array
    {
        $this->interleave();

        return array_map(
            fn (AchievementPreferences $preferences): AchievementPreferences => $preferences->userId === $this->stale->userId ? $this->stale : $preferences,
            $this->real->forUsers($userIds),
        );
    }

    public function find(string $userId): AchievementPreferences
    {
        $this->interleave();

        return strtolower($userId) === $this->stale->userId ? $this->stale : $this->real->find($userId);
    }

    public function save(AchievementPreferences $preferences): void
    {
        $this->interleave();
        $this->real->save($preferences);
    }

    public function saveShowcase(string $userId, bool $hidden, array $featured): void
    {
        $this->interleave();
        $this->real->saveShowcase($userId, $hidden, $featured);
    }

    public function saveChoices(string $userId, ?bool $announceRare, ?bool $inLeaderboard): void
    {
        $this->interleave();
        $this->real->saveChoices($userId, $announceRare, $inLeaderboard);
    }

    public function forgetFeatured(string $achievementId): int
    {
        return $this->real->forgetFeatured($achievementId);
    }

    public function deleteForUser(string $userId): void
    {
        $this->real->deleteForUser($userId);
    }

    private function interleave(): void
    {
        if ($this->interleaved) {
            return;
        }

        $this->interleaved = true;
        ($this->otherWrite)();
    }
}
