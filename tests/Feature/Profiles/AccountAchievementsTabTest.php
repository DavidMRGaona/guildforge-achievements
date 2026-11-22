<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Profiles;

use App\Application\Impersonation\Services\ImpersonationServiceInterface;
use App\Application\Services\EmailVerificationPolicyInterface;
use App\Domain\Impersonation\Enums\ImpersonationMode;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsNotices;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The «Logros» tab of the account settings (`/perfil?tab=achievements`, achievements#6): announcing
 * one's rare achievements (off by default) and appearing in the leaderboard (on by default), each
 * offered only while its mechanic is on.
 */
final class AccountAchievementsTabTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsNotices;
    use BuildsShowcase;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    protected function setUp(): void
    {
        parent::setUp();

        app('router')->getRoutes()->refreshNameLookups();
        $this->withoutStarterCatalogue();
        $this->ana = $this->person('Ana');
    }

    public function test_the_tab_shows_the_defaults(): void
    {
        $this->actingAs($this->ana)
            ->get('/perfil')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('achievementsAccountVisible', true)
                ->where('achievementsAccount.announceRare', false)
                ->where('achievementsAccount.inLeaderboard', true)
                ->where('achievementsAccount.hidden', false)
                ->where('achievementsAccount.offersAnnouncements', true)
                ->where('achievementsAccount.offersLeaderboard', false)
                ->where('achievementsAccount.threshold', 10.0)
                ->where('achievementsAccount.updateUrl', route('achievements.account.update', [], false))
                ->where('achievementsAccount.noticesUrl', '/perfil?tab=mail'));
    }

    public function test_the_leaderboard_switch_shows_with_the_leaderboard_on(): void
    {
        $this->settings(new AchievementsSettings(leaderboard: true));

        $this->actingAs($this->ana)
            ->get('/perfil')
            ->assertInertia(fn (Assert $page) => $page->where('achievementsAccount.offersLeaderboard', true));
    }

    public function test_the_tab_is_hidden_with_both_mechanics_off(): void
    {
        $this->settings(new AchievementsSettings(announcements: false, leaderboard: false));

        $this->actingAs($this->ana)
            ->get('/perfil')
            ->assertInertia(fn (Assert $page) => $page
                ->where('achievementsAccountVisible', false)
                ->where('achievementsAccount', null));
    }

    public function test_the_tab_is_hidden_while_the_email_must_be_verified(): void
    {
        $policy = $this->createStub(EmailVerificationPolicyInterface::class);
        $policy->method('mustVerify')->willReturn(true);
        $this->app->instance(EmailVerificationPolicyInterface::class, $policy);

        $this->actingAs($this->ana)
            ->get('/perfil')
            ->assertInertia(fn (Assert $page) => $page->where('achievementsAccountVisible', false));
    }

    public function test_other_pages_get_no_tab_data(): void
    {
        $this->actingAs($this->ana)
            ->get('/logros')
            ->assertInertia(fn (Assert $page) => $page
                ->where('achievementsAccount', null)
                ->where('achievementsAccountVisible', null));
    }

    public function test_the_owner_saves_their_choices_and_keeps_the_rest(): void
    {
        $this->settings(new AchievementsSettings(leaderboard: true));
        $held = $this->manualAchievement('Destacado');
        $this->grantAt($this->ana, (string) $held->id);
        $this->choose($this->ana, hidden: false, featured: [(string) $held->id]);

        $this->actingAs($this->ana)
            ->from('/perfil?tab=achievements')
            ->put('/logros/preferencias', ['announceRare' => true, 'inLeaderboard' => false])
            ->assertStatus(303)
            ->assertRedirect('/perfil?tab=achievements');

        $saved = app(AchievementPreferencesRepositoryInterface::class)->find((string) $this->ana->id);
        $this->assertTrue($saved->announceRare);
        $this->assertFalse($saved->inLeaderboard);
        $this->assertSame([strtolower((string) $held->id)], $saved->featured);
        $this->assertFalse($saved->hidden);
    }

    public function test_the_profile_section_keeps_the_tab_choices(): void
    {
        $this->allowAnnouncing($this->ana);

        $this->actingAs($this->ana)
            ->put('/logros/miembros/'.$this->handleOf($this->ana), ['hidden' => true, 'featured' => []])
            ->assertStatus(303);

        $saved = app(AchievementPreferencesRepositoryInterface::class)->find((string) $this->ana->id);
        $this->assertTrue($saved->hidden);
        $this->assertTrue($saved->announceRare);
    }

    public function test_a_choice_whose_mechanic_is_off_is_left_as_it_was(): void
    {
        // The leaderboard is off by default: its switch is not offered, so it cannot be changed
        $this->actingAs($this->ana)
            ->put('/logros/preferencias', ['announceRare' => true, 'inLeaderboard' => false])
            ->assertStatus(303);

        $saved = app(AchievementPreferencesRepositoryInterface::class)->find((string) $this->ana->id);
        $this->assertTrue($saved->announceRare);
        $this->assertTrue($saved->inLeaderboard);
    }

    public function test_the_choices_must_be_true_or_false(): void
    {
        $this->actingAs($this->ana)
            ->put('/logros/preferencias', ['announceRare' => 'quizá'])
            ->assertSessionHasErrors('announceRare');

        $this->assertFalse(app(AchievementPreferencesRepositoryInterface::class)->find((string) $this->ana->id)->announceRare);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->put('/logros/preferencias', ['announceRare' => true])->assertRedirect();

        $this->assertFalse(app(AchievementPreferencesRepositoryInterface::class)->find((string) $this->ana->id)->announceRare);
    }

    public function test_a_read_only_impersonation_saves_nothing(): void
    {
        $admin = UserModel::factory()->admin()->create();
        $this->actingAs($admin);
        app(ImpersonationServiceInterface::class)->start($admin, $this->ana, ImpersonationMode::ReadOnly);

        $this->putJson('/logros/preferencias', ['announceRare' => true])
            ->assertForbidden()
            ->assertJsonPath('error', 'impersonation_read_only');

        $this->assertFalse(app(AchievementPreferencesRepositoryInterface::class)->find((string) $this->ana->id)->announceRare);
    }
}
