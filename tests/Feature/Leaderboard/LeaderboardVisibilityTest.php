<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Leaderboard;

use App\Application\Authorization\Services\AssociationMembershipServiceInterface;
use App\Application\Services\EmailVerificationPolicyInterface;
use App\Http\Middleware\ThrottleWhereListed;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Application\Modules\DTOs\ModuleRouteDTO;
use App\Application\Services\SettingsServiceInterface;
use App\Domain\Events\UserAnonymized;
use App\Infrastructure\Time\StorageTimezoneResolver;
use Modules\Achievements\Domain\Repositories\PointsSnapshotRepositoryInterface;
use Modules\Achievements\Listeners\ForgetAnonymizedAccountPoints;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Achievements\AchievementsServiceProvider;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Application\Services\AchievementLeaderboardInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Repositories\AchievementPointsRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Infrastructure\Points\PointsSnapshotTaker;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * `/logros/clasificacion`: only for signed-in accounts that need not verify their email; only
 * profiles the viewer may see (the core's access rule), by public name, never hidden achievements,
 * opted-out, deactivated or anonymized accounts; ranks counted over what the viewer sees.
 */
final class LeaderboardVisibilityTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use BuildsShowcase;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private AchievementModel $ten;

    private AchievementModel $thirty;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->withoutStarterCatalogue();
        $this->travelTo('2026-11-22 10:00:00');
        $this->ten = $this->achievement($this->countRule(), ['name' => 'Diez', 'points' => 10]);
        $this->thirty = $this->achievement($this->countRule(), ['name' => 'Treinta', 'points' => 30]);
        $this->settingsWith(['points' => true, 'levels' => true, 'leaderboard' => true]);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/logros/clasificacion')->assertRedirect(route('login'));
    }

    public function test_it_is_not_there_while_the_leaderboard_or_points_are_off(): void
    {
        $viewer = $this->person('Vera');

        $this->settingsWith(['points' => true, 'leaderboard' => false]);
        $this->actingAs($viewer)->get('/logros/clasificacion')->assertNotFound();

        $this->settingsWith(['points' => false, 'leaderboard' => true]);
        $this->actingAs($viewer)->get('/logros/clasificacion')->assertNotFound();
    }

    public function test_an_account_that_must_verify_its_email_is_sent_to_verify_it(): void
    {
        $viewer = $this->person('Vera');
        $policy = $this->createStub(EmailVerificationPolicyInterface::class);
        $policy->method('mustVerify')->willReturn(true);
        $this->app->instance(EmailVerificationPolicyInterface::class, $policy);

        $this->actingAs($viewer)->get('/logros/clasificacion')->assertRedirect(route('verification.notice'));
    }

    public function test_a_member_sees_only_the_profiles_they_may_see_by_their_public_name(): void
    {
        $viewer = $this->person('Vera');
        $public = $this->scored('Pública', 'public', [$this->thirty]);
        $members = $this->scored('Socia', 'members', [$this->ten]);
        $this->scored('Oculta', 'hidden', [$this->thirty, $this->ten]);
        $this->scored('Asociada', 'association', [$this->thirty, $this->ten]);

        $this->snapshot();

        $this->actingAs($viewer)->get('/logros/clasificacion')
            ->assertOk()
            ->assertDontSee('oculta.login')
            ->assertDontSee('pública.login')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Achievements/Leaderboard', false)
                ->where('available', true)
                ->has('entries', 2)
                ->where('entries.0.publicName', 'Pública')
                ->where('entries.0.points', 30)
                ->where('entries.0.rank', 1)
                ->where('entries.0.profileUrl', '/miembros/'.$this->handleOf($public))
                ->where('entries.0.level.name', 'Aprendiz')
                ->where('entries.1.publicName', 'Socia')
                ->where('entries.1.rank', 2)
                ->where('entries.1.profileUrl', '/miembros/'.$this->handleOf($members))
                ->where('pagination.total', 2)
                ->where('viewer.entry', null)
                ->where('viewer.status', 'no_points'));
    }

    public function test_an_association_member_also_sees_association_profiles(): void
    {
        $viewer = $this->person('Vera');
        $this->scored('Asociada', 'association', [$this->thirty]);
        $this->scored('Socia', 'members', [$this->ten]);
        $membership = $this->createStub(AssociationMembershipServiceInterface::class);
        $membership->method('isAssociationMember')->willReturn(true);
        $membership->method('roleName')->willReturn('socio');
        $this->app->instance(AssociationMembershipServiceInterface::class, $membership);

        $this->snapshot();

        $this->actingAs($viewer)->get('/logros/clasificacion')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('entries', 2)
                ->where('entries.0.publicName', 'Asociada')
                ->where('entries.1.publicName', 'Socia'));
    }

    public function test_the_viewer_ranks_among_what_they_see_and_nobody_hidden_takes_a_place(): void
    {
        // Two people the viewer may not see have more points than the viewer
        $this->scored('Oculta', 'hidden', [$this->thirty, $this->ten]);
        $this->scored('Asociada', 'association', [$this->thirty, $this->ten]);
        $this->scored('Pública', 'public', [$this->thirty, $this->ten]);
        $viewer = $this->scored('Vera', 'members', [$this->thirty]);

        $this->snapshot();

        $this->actingAs($viewer)->get('/logros/clasificacion')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('entries', 2)
                ->where('pagination.total', 2)
                ->where('entries.1.publicName', 'Vera')
                ->where('entries.1.isViewer', true)
                ->where('viewer.entry.rank', 2)
                ->where('viewer.status', 'listed'));
    }

    public function test_the_owner_of_a_hidden_profile_sees_themselves_but_nobody_else_does(): void
    {
        $owner = $this->scored('Oculta', 'hidden', [$this->thirty]);
        $other = $this->person('Luis');

        $this->snapshot();

        $this->actingAs($owner)->get('/logros/clasificacion')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('entries', 1)
                ->where('entries.0.isViewer', true)
                ->where('viewer.entry.rank', 1));

        $this->app['auth']->forgetGuards();
        $this->actingAs($other)->get('/logros/clasificacion')
            ->assertInertia(fn (Assert $page): Assert => $page->has('entries', 0)->where('pagination.total', 0));
    }

    public function test_hidden_achievements_and_opting_out_leave_the_leaderboard_at_once(): void
    {
        $viewer = $this->person('Vera');
        $hides = $this->scored('Hilda', 'public', [$this->thirty]);
        $leaves = $this->scored('Olga', 'public', [$this->ten]);

        // A first request keeps the standings in the cache
        $this->snapshot();

        $this->actingAs($viewer)->get('/logros/clasificacion')->assertInertia(fn (Assert $page): Assert => $page->has('entries', 2));

        $this->choose($hides, hidden: true);
        $this->optOut($leaves);
        $this->app->forgetScopedInstances();

        $this->actingAs($viewer)->get('/logros/clasificacion')
            ->assertInertia(fn (Assert $page): Assert => $page->has('entries', 0));

        // And they are told why, about themselves only
        $this->app['auth']->forgetGuards();
        $this->actingAs($hides)->get('/logros/clasificacion')
            ->assertInertia(fn (Assert $page): Assert => $page->where('viewer.entry', null)->where('viewer.status', 'hidden'));
        $this->app['auth']->forgetGuards();
        $this->actingAs($leaves)->get('/logros/clasificacion')
            ->assertInertia(fn (Assert $page): Assert => $page->where('viewer.entry', null)->where('viewer.status', 'opted_out'));
    }

    public function test_deactivated_anonymized_and_unnamed_accounts_never_appear(): void
    {
        $viewer = $this->person('Vera');
        $this->scored('Desactivada', 'public', [$this->thirty])->delete();
        $anonymized = $this->scored('Anónima', 'public', [$this->thirty]);
        DB::table('users')->where('id', $anonymized->id)->update(['anonymized_at' => now()]);
        $unnamed = $this->scored('Sinnombre', 'public', [$this->thirty]);
        DB::table('users')->where('id', $unnamed->id)->update(['display_name' => null]);
        $this->scored('Pública', 'public', [$this->ten]);

        $this->snapshot();

        $this->actingAs($viewer)->get('/logros/clasificacion')
            ->assertOk()
            ->assertDontSee('sinnombre.login')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('entries', 1)
                ->where('entries.0.publicName', 'Pública')
                ->where('entries.0.rank', 1));

        // Not even to themselves without a public name: nobody is ever shown by the account name
        $this->app['auth']->forgetGuards();
        $this->actingAs($unnamed->refresh())->get('/logros/clasificacion')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('entries', 1)
                ->where('viewer.entry', null)
                ->where('viewer.status', 'no_public_name'));
    }

    public function test_others_see_totals_without_the_secrets_masked_to_them(): void
    {
        $viewer = $this->person('Vera');
        $secret = $this->achievement($this->countRule(), ['name' => 'El secreto', 'points' => 50, 'is_secret' => true]);
        $owner = $this->scored('Pública', 'public', [$this->ten, $secret]);

        $this->snapshot();

        $this->actingAs($viewer)->get('/logros/clasificacion')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('entries.0.points', 10)
                ->missing('entries.0.achievements'));

        // The owner sees every point of their own
        $this->app['auth']->forgetGuards();
        $this->actingAs($owner)->get('/logros/clasificacion')
            ->assertInertia(fn (Assert $page): Assert => $page->where('viewer.entry.points', 60));
    }

    public function test_a_secret_featured_by_one_never_tells_who_else_holds_it(): void
    {
        $viewer = $this->person('Vera');
        $s = $this->achievement($this->countRule(), ['name' => 'S', 'points' => 40, 'is_secret' => true]);
        $bea = $this->scored('Bea', 'public', [$s]);
        $this->scored('Carl', 'public', [$s, $this->ten]);
        $this->choose($bea, featured: [(string) $s->id]);
        // Somebody whose only points are a secret masked to the viewer is not listed with 0
        $this->scored('Dani', 'public', [$s]);

        $this->snapshot();

        $this->actingAs($viewer)->get('/logros/clasificacion')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('entries', 2)
                ->where('entries.0.publicName', 'Bea')
                ->where('entries.0.points', 40)
                ->where('entries.1.publicName', 'Carl')
                ->where('entries.1.points', 10));

        // Whoever holds S too sees Carl's and Dani's in full
        $this->app['auth']->forgetGuards();
        $this->actingAs($bea)->get('/logros/clasificacion')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('entries', 3)
                ->where('entries.0.publicName', 'Carl')
                ->where('entries.0.points', 50));
    }

    public function test_ties_share_a_rank(): void
    {
        $viewer = $this->person('Vera');
        $this->scored('Berta', 'public', [$this->thirty]);
        $this->scored('Alba', 'public', [$this->thirty]);
        $this->scored('Carla', 'public', [$this->ten]);

        $this->snapshot();

        $this->actingAs($viewer)->get('/logros/clasificacion')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('entries.0.publicName', 'Alba')
                ->where('entries.0.rank', 1)
                ->where('entries.1.publicName', 'Berta')
                ->where('entries.1.rank', 1)
                ->where('entries.2.rank', 3));
    }

    public function test_a_season_counts_the_grants_of_that_year_in_the_display_zone(): void
    {
        $this->settingsWith(['points' => true, 'leaderboard' => true, 'seasons_and_streaks' => true]);
        $viewer = $this->person('Vera');
        $ana = $this->scored('Ana', 'public', []);
        // 2025-12-31 23:30 UTC is already 2026 in Madrid
        $this->grantAt($ana, (string) $this->thirty->id, '2025-12-31 23:30:00');
        $this->grantAt($ana, (string) $this->ten->id, '2025-06-01 10:00:00');
        $luis = $this->scored('Luis', 'public', []);
        // A repeatable achievement granted for the 2026 season counts there, whenever it was granted
        $this->seasonGrant($luis, $this->ten, '2026', '2027-01-02 10:00:00');

        $this->snapshot();

        $this->actingAs($viewer)->get('/logros/clasificacion?temporada=2026')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('season', 2026)
                ->where('seasons', [2026, 2025])
                ->has('entries', 2)
                ->where('entries.0.publicName', 'Ana')
                ->where('entries.0.points', 30)
                ->where('entries.1.publicName', 'Luis')
                ->where('entries.1.points', 10));

        $this->actingAs($viewer)->get('/logros/clasificacion')
            ->assertInertia(fn (Assert $page): Assert => $page->where('season', null)->where('entries.0.points', 40));

        $this->actingAs($viewer)->get('/logros/clasificacion?temporada=2019')->assertNotFound();
        $this->actingAs($viewer)->get('/logros/clasificacion?temporada=dos')->assertNotFound();
    }

    public function test_seasons_switched_off_offer_all_time_only(): void
    {
        $this->settingsWith(['points' => true, 'leaderboard' => true, 'seasons_and_streaks' => false]);
        $viewer = $this->person('Vera');

        $this->actingAs($viewer)->get('/logros/clasificacion')
            ->assertInertia(fn (Assert $page): Assert => $page->where('seasons', []));
        $this->actingAs($viewer)->get('/logros/clasificacion?temporada=2026')->assertNotFound();
    }

    public function test_it_pages_and_keeps_the_viewer_row_off_the_page(): void
    {
        foreach (range(1, AchievementLeaderboardInterface::PER_PAGE + 2) as $n) {
            $this->scored(sprintf('Persona %02d', $n), 'public', [$this->thirty]);
        }
        $viewer = $this->scored('Zoe', 'public', [$this->ten]);

        $this->snapshot();

        $this->actingAs($viewer)->get('/logros/clasificacion')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('entries', AchievementLeaderboardInterface::PER_PAGE)
                ->where('pagination.lastPage', 2)
                ->where('pagination.total', AchievementLeaderboardInterface::PER_PAGE + 3)
                ->where('viewer.entry.rank', AchievementLeaderboardInterface::PER_PAGE + 3));

        $this->actingAs($viewer)->get('/logros/clasificacion?page=2')
            ->assertInertia(fn (Assert $page): Assert => $page->has('entries', 3)->where('pagination.page', 2));
    }

    public function test_others_see_a_grant_only_after_the_next_photo_and_the_owner_at_once(): void
    {
        $viewer = $this->person('Vera');
        $ana = $this->scored('Ana', 'public', [$this->ten]);
        $this->snapshot();

        $grant = app(AchievementGranterInterface::class)->grant(new NewGrant((string) $ana->id, (string) $this->thirty->id, GrantSource::Manual, silent: true, actorId: (string) $viewer->id));
        $this->assertNotNull($grant);

        // Until the night, Vera sees what Ana had: nobody sees two people's points rise at once
        $this->actingAs($viewer)->get('/logros/clasificacion')->assertInertia(fn (Assert $page): Assert => $page->where('entries.0.points', 10));

        // Ana sees her own at once
        $this->app['auth']->forgetGuards();
        $this->actingAs($ana)->get('/logros/clasificacion')->assertInertia(fn (Assert $page): Assert => $page->where('viewer.entry.points', 40));

        $this->snapshot();
        $this->app['auth']->forgetGuards();
        $this->actingAs($viewer)->get('/logros/clasificacion')->assertInertia(fn (Assert $page): Assert => $page->where('entries.0.points', 40));

        // A revoke alone changes nothing for others until the next photo either
        app(AchievementGranterInterface::class)->revoke($grant->id, (string) $viewer->id, 'Por error');
        $this->app->forgetScopedInstances();
        $this->actingAs($viewer)->get('/logros/clasificacion')->assertInertia(fn (Assert $page): Assert => $page->where('entries.0.points', 40));

        $this->snapshot();
        $this->actingAs($viewer)->get('/logros/clasificacion')->assertInertia(fn (Assert $page): Assert => $page->where('entries.0.points', 10));
    }

    public function test_an_achievement_made_secret_is_masked_at_once_without_a_new_photo(): void
    {
        $viewer = $this->person('Vera');
        $this->scored('Ana', 'public', [$this->ten, $this->thirty]);
        $this->snapshot();
        $this->actingAs($viewer)->get('/logros/clasificacion')->assertInertia(fn (Assert $page): Assert => $page->where('entries.0.points', 40));

        // «total minus what is shown» would give it away if the photo decided what is secret
        $this->thirty->refresh()->update(['is_secret' => true]);
        $this->app->forgetScopedInstances();

        $this->actingAs($viewer)->get('/logros/clasificacion')->assertInertia(fn (Assert $page): Assert => $page->where('entries.0.points', 10));
    }

    public function test_without_a_photo_nobody_is_shown_and_nothing_is_said(): void
    {
        $viewer = $this->scored('Vera', 'public', [$this->ten]);
        $this->scored('Ana', 'public', [$this->thirty]);

        $this->actingAs($viewer)->get('/logros/clasificacion')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('available', false)
                ->has('entries', 0)
                ->where('viewer.entry', null)
                ->where('viewer.status', null));
    }

    public function test_an_anonymized_account_leaves_the_photo_at_once(): void
    {
        $ana = $this->scored('Ana', 'public', [$this->ten]);
        $this->snapshot();
        $snapshots = app(PointsSnapshotRepositoryInterface::class);
        $this->assertArrayHasKey(strtolower((string) $ana->id), $snapshots->forPeriod('all'));

        app(ForgetAnonymizedAccountPoints::class)->handle(new UserAnonymized((string) $ana->id));

        $this->assertArrayNotHasKey(strtolower((string) $ana->id), $snapshots->forPeriod('all'));
    }

    public function test_a_grant_for_the_season_before_made_in_january_offers_that_season(): void
    {
        $this->travelTo('2027-01-10 10:00:00');
        $this->settingsWith(['points' => true, 'leaderboard' => true, 'seasons_and_streaks' => true]);
        $viewer = $this->person('Vera');
        $ana = $this->scored('Ana', 'public', []);
        $this->seasonGrant($ana, $this->ten, '2026', '2027-01-05 10:00:00');
        $this->snapshot();

        $this->actingAs($viewer)->get('/logros/clasificacion?temporada=2026')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('seasons', [2027, 2026])
                ->where('entries.0.points', 10));
    }

    public function test_season_bounds_hold_with_storage_in_madrid(): void
    {
        $this->useMadridStorage();
        $this->settingsWith(['points' => true, 'leaderboard' => true, 'seasons_and_streaks' => true]);
        $viewer = $this->person('Vera');
        $ana = $this->scored('Ana', 'public', []);
        // Stored in Madrid: 00:30 on the 1st is already 2026, 23:30 on the 31st still 2025
        $this->grantAt($ana, (string) $this->thirty->id, '2026-01-01 00:30:00');
        $this->grantAt($ana, (string) $this->ten->id, '2025-12-31 23:30:00');
        $this->snapshot();

        $this->actingAs($viewer)->get('/logros/clasificacion?temporada=2026')
            ->assertInertia(fn (Assert $page): Assert => $page->where('entries.0.points', 30));
        $this->actingAs($viewer)->get('/logros/clasificacion?temporada=2025')
            ->assertInertia(fn (Assert $page): Assert => $page->where('entries.0.points', 10));
    }

    public function test_a_database_failure_reading_the_seasons_shows_nobody_never_a_500(): void
    {
        $this->settingsWith(['points' => true, 'leaderboard' => true, 'seasons_and_streaks' => true]);
        $viewer = $this->person('Vera');
        $this->scored('Ana', 'public', [$this->ten]);
        $this->snapshot();
        $points = $this->createStub(AchievementPointsRepositoryInterface::class);
        $points->method('firstGrantedAt')->willThrowException(new RuntimeException('down'));
        $points->method('breakdowns')->willThrowException(new RuntimeException('down'));
        $this->app->instance(AchievementPointsRepositoryInterface::class, $points);
        $this->app->forgetScopedInstances();

        $this->actingAs($viewer)->get('/logros/clasificacion')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('available', false)
                ->has('entries', 0)
                ->where('viewer.status', null));
    }

    public function test_the_menu_editor_offers_the_page_only_while_there_is_a_leaderboard(): void
    {
        $routes = static fn (): array => array_map(
            static fn (ModuleRouteDTO $route): string => $route->routeName,
            (new AchievementsServiceProvider(app()))->registerRoutes(),
        );

        $this->assertContains('achievements.leaderboard', $routes());

        $this->settingsWith(['points' => false, 'leaderboard' => true]);
        $this->assertNotContains('achievements.leaderboard', $routes());
        $this->assertContains('achievements.index', $routes());
    }

    public function test_choosing_from_the_account_tab_is_seen_at_once(): void
    {
        $viewer = $this->person('Vera');
        $ana = $this->scored('Ana', 'public', [$this->ten]);
        $this->optOut($ana);
        // Ana is left out of the kept standings
        $this->snapshot();

        $this->actingAs($viewer)->get('/logros/clasificacion')->assertInertia(fn (Assert $page): Assert => $page->has('entries', 0));

        $this->app['auth']->forgetGuards();
        $this->actingAs($ana)->put('/logros/preferencias', ['inLeaderboard' => true])->assertStatus(303);
        $this->app->forgetScopedInstances();

        $this->app['auth']->forgetGuards();
        $this->actingAs($viewer)->get('/logros/clasificacion')->assertInertia(fn (Assert $page): Assert => $page->has('entries', 1));
    }

    public function test_the_page_has_its_own_rate_limit(): void
    {
        $route = app('router')->getRoutes()->getByName('achievements.leaderboard');

        $this->assertNotNull($route);
        $this->assertContains(ThrottleWhereListed::class.':'.AchievementsServiceProvider::LEADERBOARD_LIMITER, $route->gatherMiddleware());
    }

    /**
     * Takes the nightly photo of points now, as the schedule does at night.
     */
    private function useMadridStorage(): void
    {
        $now = now()->toDateTimeString();

        DB::table('settings')->updateOrInsert(
            ['key' => StorageTimezoneResolver::SETTING_KEY],
            ['id' => (string) Str::uuid(), 'value' => 'Europe/Madrid', 'created_at' => $now, 'updated_at' => $now],
        );

        (new StorageTimezoneResolver)->apply($this->app);
        app(SettingsServiceInterface::class)->clearCache();
        $this->app->forgetScopedInstances();
    }

    private function snapshot(): void
    {
        app(PointsSnapshotTaker::class)->take();
        $this->app->forgetScopedInstances();
    }

    /**
     * An account with a profile of that visibility holding those achievements.
     *
     * @param  list<AchievementModel>  $achievements
     */
    private function scored(string $name, string $visibility, array $achievements): UserModel
    {
        $user = $this->person($name, $visibility);

        foreach ($achievements as $achievement) {
            $this->grantAt($user, (string) $achievement->id);
        }

        return $user;
    }

    private function optOut(UserModel $user): void
    {
        $repository = app(AchievementPreferencesRepositoryInterface::class);
        $choices = $repository->find((string) $user->id);
        $repository->save($choices->withChoices(announceRare: $choices->announceRare, inLeaderboard: false));
    }

    private function seasonGrant(UserModel $user, AchievementModel $achievement, string $season, string $when): void
    {
        DB::table('achievements_grants')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => strtolower((string) $user->id),
            'achievement_id' => strtolower((string) $achievement->id),
            'season' => $season,
            'season_key' => $season,
            'granted_at' => $when,
            'source' => 'rule',
            'actor_id' => null,
            'silent' => true,
            'created_at' => $when,
            'updated_at' => $when,
        ]);
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
