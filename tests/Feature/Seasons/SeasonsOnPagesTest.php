<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Seasons;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The season going on with its progress, and the seasons already got, on the profile and on
 * `/logros`: years and totals only, and each person's progress only to themselves.
 */
final class SeasonsOnPagesTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use BuildsShowcase;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    private UserModel $luis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        config(['app.display_timezone' => 'Europe/Madrid']);
        $this->withoutStarterCatalogue();
        // 00:30 on 1 January 2027 in Madrid: the new season has just begun
        $this->travelTo('2026-12-31 23:30:00');
        $this->registerInMemorySource();
        $this->app->forgetScopedInstances();
        $this->ana = $this->person('Ana');
        $this->luis = $this->person('Luis');
    }

    public function test_the_profile_lists_the_seasons_got_and_the_owner_sees_this_seasons_progress(): void
    {
        $tenGames = $this->achievement($this->countRule(threshold: 10, extra: ['window' => ['type' => 'season']]), ['name' => 'Diez partidas al año']);
        $this->grantAt($this->ana, (string) $tenGames->id, '2025-10-01 18:00:00', '2025');
        $this->grantAt($this->ana, (string) $tenGames->id, '2026-10-01 18:00:00', '2026');
        // Last season's games do not count for this one; tonight's does
        $this->factAt($this->ana, '2026-12-20 18:00:00');
        $this->factAt($this->ana, '2026-12-31 23:15:00');

        // The night passes: others see what the nightly photo holds
        $this->refreshRarity();
        $others = $this->achievementsSection($this->render($this->ana, $this->luis))?->props;

        $this->assertNotNull($others);
        $this->assertSame(['Diez partidas al año'], array_column($others['earned'], 'name'));
        $this->assertSame(['2026', '2025'], $others['earned'][0]['seasons']);
        $this->assertTrue($others['earned'][0]['repeats']);
        $this->assertSame([], $others['locked']);

        $own = $this->achievementsSection($this->render($this->ana, $this->ana))?->props;

        $this->assertNotNull($own);
        $this->assertSame(['Diez partidas al año'], array_column($own['locked'], 'name'));
        $this->assertSame('2027', $own['locked'][0]['season']);
        $this->assertSame(['value' => 1, 'threshold' => 10, 'inMonths' => false], $own['locked'][0]['progress']);
    }

    public function test_a_streak_shows_its_progress_in_months(): void
    {
        $this->travelTo('2026-11-22 10:00:00');
        $this->achievement($this->countRule(extra: ['window' => ['type' => 'streak', 'months' => 6]]), ['name' => 'Medio año seguido']);
        $this->factAt($this->ana, '2026-09-10 18:00:00');
        $this->factAt($this->ana, '2026-10-10 18:00:00');

        $own = $this->achievementsSection($this->render($this->ana, $this->ana))?->props;

        $this->assertNotNull($own);
        $this->assertNull($own['locked'][0]['season']);
        $this->assertFalse($own['locked'][0]['repeats']);
        // November has nothing yet: the run of September and October goes on
        $this->assertSame(['value' => 2, 'threshold' => 6, 'inMonths' => true], $own['locked'][0]['progress']);
    }

    public function test_the_catalogue_tells_the_viewer_where_they_stand_this_season(): void
    {
        $tenGames = $this->achievement($this->countRule(threshold: 10, extra: ['window' => ['type' => 'season']]), ['name' => 'Diez partidas al año']);
        $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        $this->grantAt($this->ana, (string) $tenGames->id, '2026-10-01 18:00:00', '2026');
        $this->factAt($this->ana, '2026-12-31 23:15:00');

        $this->actingAs($this->ana)->get('/logros')->assertInertia(fn (Assert $page): Assert => $page
            ->where('season', '2027')
            ->where('achievements.0.name', 'Diez partidas al año')
            ->where('achievements.0.repeats', true)
            ->where('achievements.0.held', true)
            ->where('achievements.0.standing.season', '2027')
            ->where('achievements.0.standing.heldNow', false)
            ->where('achievements.0.standing.pastSeasons', ['2026'])
            ->where('achievements.0.standing.progress', ['value' => 1, 'threshold' => 10, 'inMonths' => false])
            ->where('achievements.1.name', 'Primera partida')
            ->where('achievements.1.repeats', false)
            ->where('achievements.1.standing', null));

        // Somebody else, and a guest, never see Ana's
        $this->actingAs($this->luis)->get('/logros')->assertInertia(fn (Assert $page): Assert => $page
            ->where('achievements.0.held', false)
            ->where('achievements.0.standing.pastSeasons', [])
            ->where('achievements.0.standing.progress', ['value' => 0, 'threshold' => 10, 'inMonths' => false]));

        $this->app['auth']->forgetGuards();
        $this->get('/logros')->assertInertia(fn (Assert $page): Assert => $page
            ->where('achievements.0.standing.season', '2027')
            ->where('achievements.0.standing.progress', null));
    }

    public function test_switched_off_the_seasons_keep_what_was_got_and_show_nothing_to_get(): void
    {
        $tenGames = $this->achievement($this->countRule(threshold: 10, extra: ['window' => ['type' => 'season']]), ['name' => 'Diez partidas al año']);
        $this->achievement($this->countRule(extra: ['window' => ['type' => 'streak', 'months' => 3]]), ['name' => 'Tres meses seguidos']);
        $this->grantAt($this->ana, (string) $tenGames->id, '2026-10-01 18:00:00', '2026');
        app(AchievementsSettingsInterface::class)->save(new AchievementsSettings(seasonsAndStreaks: false));

        $own = $this->achievementsSection($this->render($this->ana, $this->ana))?->props;

        $this->assertNotNull($own);
        $this->assertSame(['2026'], $own['earned'][0]['seasons']);
        $this->assertFalse($own['earned'][0]['repeats']);
        $this->assertSame([], $own['locked']);

        $this->app->forgetScopedInstances();
        $this->actingAs($this->ana)->get('/logros')->assertInertia(fn (Assert $page): Assert => $page
            ->where('season', null)
            ->has('achievements', 1)
            ->where('achievements.0.name', 'Diez partidas al año')
            ->where('achievements.0.standing', null));
    }
}
