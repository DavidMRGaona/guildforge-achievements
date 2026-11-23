<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use App\Application\SiteNotifications\DTOs\SiteNotificationItemDTO;
use App\Application\SiteNotifications\Services\SiteNotificationPreferenceServiceInterface;
use App\Application\SiteNotifications\Services\SiteNotificationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Achievements\Application\DTOs\AchievementRarityDTO;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;

/**
 * Grants as the module makes them (so AchievementGranted goes out), the bell as the core keeps it,
 * and the choices and settings that decide what is told.
 */
trait BuildsNotices
{
    protected function earn(UserModel $user, AchievementModel $achievement, GrantSource $source = GrantSource::Rule, bool $silent = false): AchievementGrant
    {
        $grant = app(AchievementGranterInterface::class)->grant(new NewGrant(
            userId: strtolower((string) $user->id),
            achievementId: (string) $achievement->id,
            source: $source,
            silent: $silent,
            actorId: in_array($source, [GrantSource::Manual, GrantSource::Bulk], true) ? $this->actor()->id : null,
        ));

        $this->assertInstanceOf(AchievementGrant::class, $grant);

        return $grant;
    }

    protected function manualAchievement(string $name, bool $secret = false): AchievementModel
    {
        return $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => $name, 'is_secret' => $secret]);
    }

    /**
     * @return list<SiteNotificationItemDTO>
     */
    protected function bell(UserModel $user): array
    {
        return app(SiteNotificationServiceInterface::class)->latest($user, 20);
    }

    /**
     * @return list<SiteNotificationItemDTO>
     */
    protected function bellOf(UserModel $user, string $category): array
    {
        return array_values(array_filter($this->bell($user), static fn (SiteNotificationItemDTO $item): bool => $item->category === $category));
    }

    protected function turnOff(UserModel $user, string $category): void
    {
        app(SiteNotificationPreferenceServiceInterface::class)->update($user, [$category => false]);
    }

    protected function allowAnnouncing(UserModel $user, bool $announce = true, bool $hidden = false): void
    {
        $preferences = app(AchievementPreferencesRepositoryInterface::class);
        $current = $preferences->find((string) $user->id);
        $preferences->save($current->with($hidden, $current->featured)->withChoices(announceRare: $announce, inLeaderboard: $current->inLeaderboard));
    }

    /**
     * @param  array<string, float>  $shares  achievement id => percent of active people holding it
     */
    protected function rarity(array $shares, int $activePeople = 100): void
    {
        $fixed = new FixedAchievementRarity(new AchievementRarityDTO($activePeople, array_change_key_case($shares)));
        $this->app->scoped(AchievementRarityInterface::class, static fn (): FixedAchievementRarity => $fixed);
        $this->app->forgetScopedInstances();
    }

    protected function settings(AchievementsSettings $settings): void
    {
        app(AchievementsSettingsInterface::class)->save($settings);
        $this->app->forgetScopedInstances();
    }

    private ?UserModel $grantingActor = null;

    private function actor(): UserModel
    {
        return $this->grantingActor ??= UserModel::factory()->create();
    }
}
