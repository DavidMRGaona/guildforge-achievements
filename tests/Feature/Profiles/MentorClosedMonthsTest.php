<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Profiles;

use App\Application\Profiles\DTOs\ResolvedProfileDTO;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\DTOs\AchievementViewDTO;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Application\Services\AchievementShowcaseInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Others see a «Mentor» grant only once its month is over in the display zone (#37): every
 * newcomer of a month looks the same, so a grant cannot be paired with somebody's first game two
 * weeks before. On top of the nightly photo, for everything others see; the owner sees it at once.
 */
final class MentorClosedMonthsTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use BuildsShowcase;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    private UserModel $luis;

    private string $mentor;

    private string $mentorOfThree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->withoutStarterCatalogue();
        $this->ana = $this->person('Ana');
        $this->luis = $this->person('Luis');
        app(AchievementsSettingsInterface::class)->save(AchievementsSettings::fromArray(['points' => true, 'levels' => true, 'leaderboard' => true]));
        $this->mentor = (string) $this->achievement(['version' => 1, 'metric' => 'mentor', 'threshold' => 1], ['name' => 'Mentor', 'points' => 20])->id;
        $this->mentorOfThree = (string) $this->achievement(['version' => 1, 'metric' => 'mentor', 'threshold' => 3], ['name' => 'Mentor de tres', 'points' => 30])->id;
        $first = (string) $this->achievement($this->countRule(), ['name' => 'Primera partida', 'points' => 5])->id;

        // This month's mentor, last month's, and an achievement of another kind this month
        $this->grantAt($this->ana, $this->mentor, '2026-11-20 03:00:00');
        $this->grantAt($this->ana, $this->mentorOfThree, '2026-10-20 03:00:00');
        $this->grantAt($this->ana, $first, '2026-11-20 18:00:00');
        $this->factAt($this->ana, '2026-11-01 18:00:00');
        $this->factAt($this->luis, '2026-11-01 18:00:00');

        $this->photoAt('2026-11-23 03:30:00');
        $this->travelTo('2026-11-23 10:00:00');
    }

    public function test_others_see_only_the_mentor_grants_of_months_already_over(): void
    {
        $others = $this->render($this->ana, $this->luis);

        $this->assertSame(['Primera partida', 'Mentor de tres'], $this->earned($others));
        $this->assertSame(2, $this->countStat($others)?->value);
        $this->assertSame(35, $this->achievementsSection($others)?->props['points']['total'] ?? null);

        // The owner sees everything at once
        $own = $this->render($this->ana, $this->ana);
        $this->assertSame(['Primera partida', 'Mentor', 'Mentor de tres'], $this->earned($own));
        $this->assertSame(55, $this->achievementsSection($own)?->props['points']['total'] ?? null);
    }

    public function test_the_leaderboard_and_the_rarity_leave_this_month_s_mentors_out(): void
    {
        $this->actingAs($this->luis)->get('/logros/clasificacion')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('entries.0.publicName', 'Ana')
                ->where('entries.0.points', 35));

        $shares = app(AchievementRarityInterface::class)->current()->shares;
        $this->assertArrayNotHasKey(strtolower($this->mentor), $shares);
        $this->assertArrayHasKey(strtolower($this->mentorOfThree), $shares);
    }

    public function test_a_featured_mentor_of_this_month_waits_for_its_month_for_everybody(): void
    {
        $this->choose($this->ana, featured: [$this->mentor]);
        $this->app->forgetScopedInstances();

        $featured = app(AchievementShowcaseInterface::class)->featured([strtolower((string) $this->ana->id)]);
        $this->assertNotContains('Mentor', array_map(static fn (AchievementViewDTO $view): string => $view->name, $featured[strtolower((string) $this->ana->id)] ?? []));

        $this->photoAt('2026-12-01 03:30:00');

        $featured = app(AchievementShowcaseInterface::class)->featured([strtolower((string) $this->ana->id)]);
        $this->assertSame(['Mentor'], array_map(static fn (AchievementViewDTO $view): string => $view->name, $featured[strtolower((string) $this->ana->id)] ?? []));
    }

    public function test_others_see_this_month_s_mentors_out_of_the_season_s_points_too(): void
    {
        app(AchievementsSettingsInterface::class)->save(AchievementsSettings::fromArray(['points' => true, 'levels' => true, 'leaderboard' => true, 'seasons_and_streaks' => true]));
        $this->app->forgetScopedInstances();

        $this->actingAs($this->luis)->get('/logros/clasificacion?temporada=2026')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('entries.0.publicName', 'Ana')
                ->where('entries.0.points', 35));
    }

    public function test_once_the_month_is_over_the_next_photo_shows_it(): void
    {
        $this->photoAt('2026-12-01 03:30:00');

        $others = $this->render($this->ana, $this->luis);

        $this->assertSame(['Mentor', 'Primera partida', 'Mentor de tres'], $this->earned($others));
        $this->assertSame(55, $this->achievementsSection($others)?->props['points']['total'] ?? null);
        $this->assertArrayHasKey(strtolower($this->mentor), app(AchievementRarityInterface::class)->current()->shares);
    }

    public function test_the_month_is_the_site_s_not_the_storage_zone_s(): void
    {
        // 23:30 UTC on the 30th is already December in Madrid
        $late = (string) $this->achievement(['version' => 1, 'metric' => 'mentor', 'threshold' => 2], ['name' => 'Mentor de dos', 'points' => 1])->id;
        $this->grantAt($this->ana, $late, '2026-11-30 23:30:00');

        $this->photoAt('2026-12-01 03:30:00');

        $this->assertSame('Europe/Madrid', config('app.display_timezone'));
        $this->assertNotContains('Mentor de dos', $this->earned($this->render($this->ana, $this->luis)));
        $this->assertContains('Mentor de dos', $this->earned($this->render($this->ana, $this->ana)));
    }

    public function test_a_mentor_rule_that_cannot_be_read_is_held_back_alike_in_the_list_and_the_points(): void
    {
        // Another version, say: unreadable whole, but its metric still says mentor, as SQL reads it
        $broken = (string) $this->achievement('{"version":2,"metric":"mentor","threshold":1}', ['name' => 'Mentor roto', 'points' => 7])->id;
        $this->grantAt($this->ana, $broken, '2026-11-20 03:00:00');
        $this->photoAt('2026-11-23 03:30:00');

        $others = $this->render($this->ana, $this->luis);

        $this->assertNotContains('Mentor roto', $this->earned($others));
        $this->assertSame(35, $this->achievementsSection($others)?->props['points']['total'] ?? null);
    }

    private function photoAt(string $when): void
    {
        $this->travelTo($when);
        $this->refreshRarity();
        $this->app->forgetScopedInstances();
    }

    /**
     * @return list<string>
     */
    private function earned(ResolvedProfileDTO $profile): array
    {
        return array_column($this->achievementsSection($profile)?->props['earned'] ?? [], 'name');
    }
}
