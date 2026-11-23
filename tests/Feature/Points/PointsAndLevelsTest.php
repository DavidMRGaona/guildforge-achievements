<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Points;

use App\Application\Profiles\DTOs\ResolvedProfileDTO;
use App\Application\Profiles\DTOs\ResolvedProfileStatDTO;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementPointsInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Infrastructure\Points\PointsSnapshotTaker;
use Modules\Achievements\Infrastructure\Profiles\AchievementsLevelProfileStat;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Points add up what each achievement gives, secret ones included; levels climb them. Both are
 * totals on the profile, and both disappear for others once the owner hid their achievements.
 */
final class PointsAndLevelsTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use BuildsShowcase;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    private UserModel $luis;

    private AchievementModel $twenty;

    private AchievementModel $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->withoutStarterCatalogue();
        $this->travelTo('2026-11-22 10:00:00');
        $this->ana = $this->person('Ana');
        $this->luis = $this->person('Luis');
        $this->twenty = $this->achievement($this->countRule(), ['name' => 'Veinte', 'points' => 20]);
        $this->secret = $this->achievement($this->countRule(), ['name' => 'El secreto', 'points' => 15, 'is_secret' => true]);
        $this->grantAt($this->ana, (string) $this->twenty->id);
        $this->grantAt($this->ana, (string) $this->secret->id, '2025-03-01 10:00:00');
        $this->settingsWith(['points' => true, 'levels' => true, 'leaderboard' => true, 'seasons_and_streaks' => true]);
        $this->snapshot();
    }

    public function test_the_summary_counts_secrets_inactive_ones_and_the_season(): void
    {
        $this->twenty->update(['is_active' => false]);
        $this->app->forgetScopedInstances();

        $summary = app(AchievementPointsInterface::class)->summary((string) $this->ana->id, (string) $this->ana->id);

        $this->assertNotNull($summary);
        $this->assertSame(35, $summary->total);
        $this->assertSame(20, $summary->season);
        $this->assertSame(2026, $summary->seasonYear);
        $this->assertSame('Aprendiz', $summary->level?->name);
        $this->assertSame(2, $summary->level->number);
        $this->assertSame(50, $summary->nextLevel?->threshold);
    }

    public function test_the_level_stat_shows_for_others_and_links_the_leaderboard(): void
    {
        $stat = $this->levelStat($this->render($this->ana, $this->luis));

        $this->assertSame(2, $stat?->value);
        $this->assertSame('/logros/clasificacion', $stat->url);
        $this->assertSame('Nivel', $stat->label);
    }

    public function test_no_level_without_points_while_levels_or_points_are_off_or_once_hidden(): void
    {
        $this->assertNull($this->levelStat($this->render($this->luis, $this->ana)));

        $this->settingsWith(['points' => false, 'levels' => true]);
        $this->assertNull($this->levelStat($this->render($this->ana, $this->luis)));

        $this->settingsWith(['points' => true, 'levels' => false]);
        $this->assertNull($this->levelStat($this->render($this->ana, $this->luis)));

        $this->settingsWith(['points' => true, 'levels' => true]);
        $this->choose($this->ana, hidden: true);
        $this->assertNull($this->levelStat($this->render($this->ana, $this->luis)));
        $this->assertSame(2, $this->levelStat($this->render($this->ana, $this->ana))?->value);
    }

    public function test_others_get_no_points_of_the_secrets_masked_to_them(): void
    {
        $props = $this->achievementsSection($this->render($this->ana, $this->luis))?->props;

        $this->assertIsArray($props);
        $this->assertSame(1, $props['secretCount']);
        $this->assertSame(['Veinte'], array_column($props['earned'], 'name'));
        $this->assertSame(20, $props['earned'][0]['points']);
        // 35 with the secret: a total minus what is shown would give its 15 points away
        $this->assertSame(20, $props['points']['total']);
        $this->assertArrayNotHasKey('secretPoints', $props['points']);
        $this->assertSame(20, $props['points']['season']);
        $this->assertSame('Aprendiz', $props['points']['level']['name']);
        // The next level is the owner's own progress
        $this->assertNull($props['points']['nextLevel']);
        $this->assertStringNotContainsString('El secreto', (string) json_encode($props));
        $this->assertSame(2, $this->levelStat($this->render($this->ana, $this->luis))?->value);
    }

    public function test_a_featured_secret_and_one_the_viewer_holds_add_up_and_never_tell_who_else_holds_it(): void
    {
        // Bea features S (revealing it and its points); Carl holds S without featuring it
        $s = $this->achievement($this->countRule(), ['name' => 'S', 'points' => 40, 'is_secret' => true]);
        $bea = $this->person('Bea');
        $carl = $this->person('Carl');
        $this->grantAt($bea, (string) $s->id);
        $this->grantAt($carl, (string) $s->id);
        $this->grantAt($carl, (string) $this->twenty->id);
        $this->choose($bea, featured: [(string) $s->id]);
        $this->snapshot();

        $beaSeen = $this->achievementsSection($this->render($bea, $this->luis))?->props;
        $carlSeen = $this->achievementsSection($this->render($carl, $this->luis))?->props;

        $this->assertSame(40, $beaSeen['points']['total'] ?? null);
        // Carl's 40 of S are not added: «1 secret» and a total that says nothing about which
        $this->assertSame(1, $carlSeen['secretCount'] ?? null);
        $this->assertSame(20, $carlSeen['points']['total'] ?? null);

        // Whoever holds S sees it on Carl, and his full total
        $this->assertSame(60, $this->achievementsSection($this->render($carl, $bea))?->props['points']['total'] ?? null);
        // Carl sees his own in full
        $this->assertSame(60, $this->achievementsSection($this->render($carl, $carl))?->props['points']['total'] ?? null);
    }

    public function test_a_masked_secret_that_crosses_a_level_leaves_others_on_the_level_below(): void
    {
        // Ana: 20 visible + 15 secret = 35; with 30 more secret points she reaches «Habitual» (50)
        $more = $this->achievement($this->countRule(), ['name' => 'Otro secreto', 'points' => 30, 'is_secret' => true]);
        $this->grantAt($this->ana, (string) $more->id);
        $this->snapshot();

        $this->assertSame(3, $this->levelStat($this->render($this->ana, $this->ana))?->value);
        // Luis holds neither secret: he sees 20 points, «Aprendiz»
        $this->assertSame(2, $this->levelStat($this->render($this->ana, $this->luis))?->value);
        $this->assertSame('Aprendiz', $this->achievementsSection($this->render($this->ana, $this->luis))?->props['points']['level']['name'] ?? null);
    }

    public function test_others_see_last_nights_points_and_the_owner_at_once(): void
    {
        $forty = $this->achievement($this->countRule(), ['name' => 'Cuarenta', 'points' => 40]);
        $this->grantAt($this->ana, (string) $forty->id, '2026-11-22 09:00:00');

        $this->assertSame(20, $this->achievementsSection($this->render($this->ana, $this->luis))?->props['points']['total'] ?? null);
        $this->assertSame(2, $this->levelStat($this->render($this->ana, $this->luis))?->value);
        $this->assertSame(75, $this->achievementsSection($this->render($this->ana, $this->ana))?->props['points']['total'] ?? null);

        $this->snapshot();

        $this->assertSame(60, $this->achievementsSection($this->render($this->ana, $this->luis))?->props['points']['total'] ?? null);
        $this->assertSame(3, $this->levelStat($this->render($this->ana, $this->luis))?->value);
    }

    public function test_without_a_photo_others_see_no_points_never_a_zero(): void
    {
        DB::table('achievements_snapshot_runs')->delete();
        DB::table('achievements_points_snapshots')->delete();

        $props = $this->achievementsSection($this->render($this->ana, $this->luis))?->props;

        $this->assertIsArray($props);
        $this->assertArrayNotHasKey('points', $props);
        $this->assertNull($this->levelStat($this->render($this->ana, $this->luis)));
        // The owner still sees their own
        $this->assertSame(35, $this->achievementsSection($this->render($this->ana, $this->ana))?->props['points']['total'] ?? null);
    }

    public function test_a_guest_gets_no_link_to_the_leaderboard(): void
    {
        $this->assertSame('/logros', $this->levelStat($this->render($this->ana, null))?->url);
        $props = $this->achievementsSection($this->render($this->ana, null))?->props;

        $this->assertIsArray($props);
        $this->assertArrayHasKey('points', $props);
        $this->assertNull($props['points']['leaderboardUrl']);
    }

    public function test_the_owner_sees_their_next_level_and_the_leaderboard_choice(): void
    {
        $props = $this->achievementsSection($this->render($this->ana, $this->ana))?->props;

        $this->assertIsArray($props);
        $this->assertSame(35, $props['points']['total']);
        $this->assertSame('Habitual', $props['points']['nextLevel']['name']);
        $this->assertSame('/logros/clasificacion', $props['points']['leaderboardUrl']);
        $this->assertTrue($props['owner']['leaderboard']);
    }

    public function test_with_points_off_nothing_says_points(): void
    {
        $this->settingsWith(['points' => false, 'levels' => true, 'leaderboard' => true]);

        $props = $this->achievementsSection($this->render($this->ana, $this->ana))?->props;

        $this->assertIsArray($props);
        $this->assertArrayNotHasKey('points', $props);
        $this->assertArrayNotHasKey('points', $props['earned'][0]);
        $this->assertArrayNotHasKey('leaderboard', $props['owner']);
    }

    public function test_the_owner_opts_out_of_the_leaderboard_from_the_section(): void
    {
        $this->actingAs($this->ana)
            ->put('/logros/miembros/'.$this->handleOf($this->ana), ['hidden' => false, 'featured' => [], 'leaderboard' => false])
            ->assertStatus(303);

        $choices = app(AchievementPreferencesRepositoryInterface::class)->find((string) $this->ana->id);
        $this->assertFalse($choices->inLeaderboard);

        // Leaving it out keeps it as it was
        $this->actingAs($this->ana)
            ->put('/logros/miembros/'.$this->handleOf($this->ana), ['hidden' => true, 'featured' => []])
            ->assertStatus(303);

        $choices = app(AchievementPreferencesRepositoryInterface::class)->find((string) $this->ana->id);
        $this->assertFalse($choices->inLeaderboard);
        $this->assertTrue($choices->hidden);
    }

    public function test_the_catalogue_shows_points_and_the_leaderboard_to_signed_in_people(): void
    {
        $this->registerInMemorySource();
        $this->app->forgetScopedInstances();

        $this->actingAs($this->luis)->get('/logros')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('showsPoints', true)
                ->where('leaderboardUrl', '/logros/clasificacion')
                ->where('achievements.0.masked', true)
                ->where('achievements.0.points', null)
                ->where('achievements.1.name', 'Veinte')
                ->where('achievements.1.points', 20));

        $this->app['auth']->forgetGuards();
        $this->get('/logros')->assertInertia(fn (Assert $page): Assert => $page->where('leaderboardUrl', null));
    }

    private function snapshot(): void
    {
        app(PointsSnapshotTaker::class)->take();
        $this->app->forgetScopedInstances();
    }

    private function levelStat(ResolvedProfileDTO $profile): ?ResolvedProfileStatDTO
    {
        foreach ($profile->stats as $stat) {
            if ($stat->key === AchievementsLevelProfileStat::KEY) {
                return $stat;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function settingsWith(array $values): void
    {
        app(AchievementsSettingsInterface::class)->save(AchievementsSettings::fromArray($values));
        $this->app->forgetScopedInstances();
    }
}
