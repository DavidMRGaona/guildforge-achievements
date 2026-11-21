<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Filament;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Support\ModuleSettingsPages;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Achievements\AchievementsServiceProvider;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\ValueObjects\MetricKinds;
use Modules\Achievements\Filament\Pages\AchievementsSettingsPage;
use Tests\Support\Modules\ModuleTestCase;

final class AchievementsSettingsPageTest extends ModuleTestCase
{
    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel(pages: [AchievementsSettingsPage::class]);
    }

    public function test_the_provider_declares_the_page_and_registers_it(): void
    {
        $provider = new AchievementsServiceProvider($this->app);

        $this->assertSame(AchievementsSettingsPage::class, $provider->getSettingsPage());
        $this->assertContains(AchievementsSettingsPage::class, $provider->registerFilamentPages());
        $this->assertSame([], $provider->getSettingsSchema());
    }

    public function test_the_page_sits_in_the_settings_cluster_with_its_slot(): void
    {
        $this->assertSame(SettingsCluster::class, AchievementsSettingsPage::getCluster());
        $this->assertSame(128, AchievementsSettingsPage::getNavigationSort());
        $this->assertSame('Logros', AchievementsSettingsPage::getNavigationLabel());
    }

    public function test_the_configure_permission_opens_the_page_with_the_current_values(): void
    {
        $this->actingAs($this->userWith(AchievementsSettingsPage::PERMISSION));

        $this->assertTrue(AchievementsSettingsPage::canAccess());
        Livewire::test(AchievementsSettingsPage::class)
            ->assertOk()
            ->assertFormSet([
                'progress' => true,
                'points' => false,
                'announcements_rarity_threshold' => 10.0,
                'recognitions_window_days' => 14,
                'metric_kinds.game_session' => true,
            ]);
    }

    public function test_the_other_permissions_are_not_enough(): void
    {
        foreach (['achievements:achievements.view_any', 'achievements:achievements.manage', 'achievements:achievements.grant', 'modules.configure'] as $key) {
            $this->actingAs($this->userWith($key));

            $this->assertFalse(AchievementsSettingsPage::canAccess(), $key);
        }
    }

    public function test_a_guest_cannot_open_the_page(): void
    {
        $this->assertFalse(AchievementsSettingsPage::canAccess());
    }

    public function test_the_module_button_opens_the_page_only_for_those_who_may_enter(): void
    {
        $resolver = $this->app->make(ModuleSettingsPages::class);

        $this->assertNotNull($resolver->pageUrl('achievements'));

        $this->actingAs($this->userWith('modules.configure'));
        $this->assertNull($resolver->accessibleUrl('achievements'));

        $this->actingAs($this->userWith(AchievementsSettingsPage::PERMISSION));
        $this->assertNotNull($resolver->accessibleUrl('achievements'));
    }

    public function test_saving_stores_every_switch(): void
    {
        $this->actingAs($this->userWith(AchievementsSettingsPage::PERMISSION));

        Livewire::test(AchievementsSettingsPage::class)
            ->fillForm([
                'progress' => false,
                'rarity' => false,
                'points' => true,
                'levels' => true,
                'leaderboard' => true,
                'recognitions' => true,
                'recognitions_window_days' => 30,
                'announcements_rarity_threshold' => 2.5,
                'challenges' => true,
                'metric_kinds.forum_post' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->app->forgetScopedInstances();
        $settings = $this->app->make(AchievementsSettingsInterface::class);

        $this->assertFalse($settings->progressEnabled());
        $this->assertFalse($settings->rarityEnabled());
        $this->assertTrue($settings->secretsEnabled());
        $this->assertTrue($settings->pointsEnabled());
        $this->assertTrue($settings->levelsEnabled());
        $this->assertTrue($settings->leaderboardEnabled());
        $this->assertTrue($settings->recognitionsEnabled());
        $this->assertSame(30, $settings->recognitionsWindowDays());
        $this->assertSame(2.5, $settings->announcementsRarityThreshold());
        $this->assertTrue($settings->challengesEnabled());
        $this->assertFalse($settings->metricKindEnabled(MetricKinds::FORUM_POST));
        $this->assertTrue($settings->metricKindEnabled(MetricKinds::EVENT));
    }

    public function test_a_threshold_or_a_window_out_of_range_is_refused(): void
    {
        $this->actingAs($this->userWith(AchievementsSettingsPage::PERMISSION));

        Livewire::test(AchievementsSettingsPage::class)
            ->fillForm(['announcements_rarity_threshold' => 150, 'recognitions' => true, 'recognitions_window_days' => 0])
            ->call('save')
            ->assertHasFormErrors(['announcements_rarity_threshold', 'recognitions_window_days']);

        $this->assertNull(DB::table('settings')->where('key', 'module_settings:achievements')->first());
    }

    public function test_the_values_of_a_switch_that_is_off_are_kept(): void
    {
        $this->actingAs($this->userWith(AchievementsSettingsPage::PERMISSION));
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(
            announcementsRarityThreshold: 3.0,
            recognitions: true,
            recognitionsWindowDays: 21,
        ));

        // Turning the switches off hides their fields: the values are not in the state
        Livewire::test(AchievementsSettingsPage::class)
            ->fillForm(['announcements' => false, 'recognitions' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->app->forgetScopedInstances();
        $settings = $this->app->make(AchievementsSettingsInterface::class);

        $this->assertFalse($settings->announcementsEnabled());
        $this->assertFalse($settings->recognitionsEnabled());
        $this->assertSame(3.0, $settings->announcementsRarityThreshold());
        $this->assertSame(21, $settings->recognitionsWindowDays());
    }

    public function test_a_stored_kind_the_form_does_not_list_is_kept(): void
    {
        $this->actingAs($this->userWith(AchievementsSettingsPage::PERMISSION));
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(metricKinds: ['future_kind' => false]));

        Livewire::test(AchievementsSettingsPage::class)
            ->fillForm(['metric_kinds.event' => false])
            ->call('save');

        $this->app->forgetScopedInstances();
        $kinds = $this->app->make(AchievementsSettingsInterface::class)->metricKinds();

        $this->assertFalse($kinds['future_kind']);
        $this->assertFalse($kinds['event']);
    }

    public function test_saving_without_the_permission_is_refused(): void
    {
        $this->actingAsPanelAdmin();
        $component = Livewire::test(AchievementsSettingsPage::class)->fillForm(['points' => true]);

        $this->actingAs($this->userWith('modules.configure'));

        $component->call('save')->assertForbidden();

        $this->assertNull(DB::table('settings')->where('key', 'module_settings:achievements')->first());
    }

    private function userWith(string $permissionKey): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);
        $permission = PermissionModel::query()->firstOrCreate(
            ['key' => $permissionKey],
            ['label' => $permissionKey, 'resource' => 'achievements', 'action' => 'test', 'module' => 'achievements'],
        );
        $role->permissions()->attach($permission->id);
        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
