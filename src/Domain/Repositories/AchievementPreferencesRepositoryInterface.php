<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

use Modules\Achievements\Domain\ValueObjects\AchievementPreferences;

/**
 * Each person's choices (`achievements_preferences`). A person without a row has chosen nothing.
 */
interface AchievementPreferencesRepositoryInterface
{
    /**
     * The choices of every account asked, in one query, keyed by account id in lower case; an
     * account without a row gets AchievementPreferences::none().
     *
     * @param  list<string>  $userIds
     * @return array<string, AchievementPreferences>
     */
    public function forUsers(array $userIds): array;

    public function find(string $userId): AchievementPreferences;

    public function save(AchievementPreferences $preferences): void;

    /**
     * Takes an achievement out of everybody's featured choices (it became secret: nobody chose to
     * reveal it as such). Returns how many accounts had it.
     */
    public function forgetFeatured(string $achievementId): int;

    /**
     * Forgets an account's choices (anonymization).
     */
    public function deleteForUser(string $userId): void;
}
