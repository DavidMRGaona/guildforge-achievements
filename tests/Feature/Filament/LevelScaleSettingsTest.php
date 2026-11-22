<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Filament;

use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Livewire\Livewire;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Filament\Pages\AchievementsSettingsPage;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The levels of «Puntos y clasificación» (#10): six starter levels until somebody edits them,
 * shown while levels are on, and refused when they are not a scale.
 */
final class LevelScaleSettingsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel(pages: [AchievementsSettingsPage::class]);
        $this->actingAs($this->userWith(AchievementsSettingsPage::PERMISSION));
    }

    public function test_the_starter_scale_has_six_levels_from_zero(): void
    {
        $scale = app(AchievementsSettingsInterface::class)->levelScale();

        $this->assertCount(6, $scale->levels);
        $this->assertSame(0, $scale->levels[0]->threshold);
        $this->assertSame('Primeros pasos', $scale->levels[0]->name);
        $this->assertSame('Leyenda', $scale->levels[5]->name);
    }

    public function test_the_page_shows_the_scale_while_levels_are_on_and_saves_it(): void
    {
        Livewire::test(AchievementsSettingsPage::class)
            ->assertFormFieldHidden('levels_scale')
            ->fillForm(['points' => true, 'levels' => true])
            ->assertFormFieldVisible('levels_scale')
            ->fillForm(['levels_scale' => [
                ['threshold' => 0, 'name' => 'Novato'],
                ['threshold' => 100, 'name' => 'Experto'],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->app->forgetScopedInstances();
        $settings = app(AchievementsSettingsInterface::class);

        $this->assertTrue($settings->levelsShown());
        $this->assertSame([['threshold' => 0, 'name' => 'Novato'], ['threshold' => 100, 'name' => 'Experto']], $settings->levelScale()->toArray());
    }

    public function test_a_scale_that_does_not_climb_is_refused_and_nothing_is_saved(): void
    {
        Livewire::test(AchievementsSettingsPage::class)
            ->fillForm(['points' => true, 'levels' => true, 'levels_scale' => [
                ['threshold' => 10, 'name' => 'Novato'],
                ['threshold' => 5, 'name' => 'Experto'],
            ]])
            ->call('save')
            ->assertHasErrors(['data.levels_scale']);

        $this->app->forgetScopedInstances();
        $this->assertFalse(app(AchievementsSettingsInterface::class)->levelsEnabled());
    }

    public function test_switching_levels_off_keeps_the_stored_scale(): void
    {
        $custom = AchievementsSettings::fromArray(['levels' => true, 'levels_scale' => [['threshold' => 0, 'name' => 'A'], ['threshold' => 3, 'name' => 'B']]]);
        app(AchievementsSettingsInterface::class)->save($custom);
        $this->app->forgetScopedInstances();

        Livewire::test(AchievementsSettingsPage::class)
            ->fillForm(['levels' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->app->forgetScopedInstances();
        $this->assertSame('B', app(AchievementsSettingsInterface::class)->levelScale()->levels[1]->name);
    }

    public function test_a_malformed_stored_scale_reads_as_the_starter_one(): void
    {
        $settings = AchievementsSettings::fromArray(['levels_scale' => [['threshold' => 5, 'name' => 'Mal']]]);

        $this->assertContains('levels_scale', $settings->invalidKeys);
        $this->assertSame('Primeros pasos', $settings->levelScale()->levels[0]->name);
        $this->assertSame([], AchievementsSettings::fromArray([])->invalidKeys);
    }

    public function test_levels_and_the_leaderboard_need_points(): void
    {
        $settings = AchievementsSettings::fromArray(['levels' => true, 'leaderboard' => true]);

        $this->assertFalse($settings->levelsShown());
        $this->assertFalse($settings->leaderboardShown());
        $this->assertTrue(AchievementsSettings::fromArray(['points' => true, 'levels' => true, 'leaderboard' => true])->leaderboardShown());
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
