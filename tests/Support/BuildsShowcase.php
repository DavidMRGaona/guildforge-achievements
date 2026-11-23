<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use App\Application\Profiles\DTOs\ProfileOwnerDTO;
use App\Application\Profiles\DTOs\ResolvedProfileDTO;
use App\Application\Profiles\DTOs\ResolvedProfileSectionDTO;
use App\Application\Profiles\DTOs\ResolvedProfileStatDTO;
use App\Application\Profiles\Services\ProfileRendererInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserProfileModel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\AchievementPreferences;
use Modules\Achievements\Infrastructure\Profiles\AchievementsCountProfileStat;
use Modules\Achievements\Infrastructure\Profiles\AchievementsProfileSection;

/**
 * People with a public profile, their facts and grants written straight to the tables (with the
 * date the test needs), and profiles drawn as the core's page draws them.
 */
trait BuildsShowcase
{
    protected function person(string $name, string $visibility = 'members'): UserModel
    {
        $user = UserModel::factory()->create(['name' => strtolower($name).'.login', 'display_name' => $name]);
        UserProfileModel::query()->where('user_id', $user->id)->update(['visibility' => $visibility]);

        return $user;
    }

    protected function handleOf(UserModel $user): string
    {
        return (string) UserProfileModel::query()->where('user_id', $user->id)->value('handle');
    }

    protected function grantAt(UserModel|string $user, string $achievementId, string $when = '2026-10-01 18:00:00', ?string $season = null): void
    {
        DB::table('achievements_grants')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => strtolower($user instanceof UserModel ? (string) $user->id : $user),
            'achievement_id' => strtolower($achievementId),
            'season' => $season,
            'season_key' => $season ?? '',
            'granted_at' => $when,
            'source' => 'rule',
            'actor_id' => null,
            'silent' => true,
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    /**
     * A fact of the ledger, bypassing the ledger's own checks (an anonymized or missing account).
     */
    protected function factAt(UserModel|string $user, string $when, string $kind = 'game_session'): void
    {
        DB::table('achievements_facts')->insert([
            'id' => (string) Str::uuid7(),
            'source_type' => 'achievements.test',
            'subject_id' => (string) Str::uuid7(),
            'user_id' => strtolower($user instanceof UserModel ? (string) $user->id : $user),
            'role' => 'player',
            'kind' => $kind,
            'occurred_at' => $when,
            'attributes' => '{}',
            'placement' => null,
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    /**
     * @param  list<string>  $featured
     */
    protected function choose(UserModel $user, bool $hidden = false, array $featured = []): void
    {
        app(AchievementPreferencesRepositoryInterface::class)->save(new AchievementPreferences(strtolower((string) $user->id), $hidden, $featured));
    }

    protected function refreshRarity(): void
    {
        app(AchievementRarityInterface::class)->refresh();
    }

    protected function render(UserModel $owner, ?Authenticatable $viewer): ResolvedProfileDTO
    {
        // Each profile is its own request: what is bound scoped (choices, rarity) starts afresh
        $this->app->forgetScopedInstances();

        return app(ProfileRendererInterface::class)->render(
            new ProfileOwnerDTO(strtolower((string) $owner->id), $this->handleOf($owner), $owner->display_name),
            $viewer,
        );
    }

    protected function achievementsSection(ResolvedProfileDTO $profile): ?ResolvedProfileSectionDTO
    {
        foreach ($profile->main as $section) {
            if ($section->key === AchievementsProfileSection::KEY) {
                return $section;
            }
        }

        return null;
    }

    protected function countStat(ResolvedProfileDTO $profile): ?ResolvedProfileStatDTO
    {
        foreach ($profile->stats as $stat) {
            if ($stat->key === AchievementsCountProfileStat::KEY) {
                return $stat;
            }
        }

        return null;
    }
}
