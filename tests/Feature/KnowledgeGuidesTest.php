<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Livewire\Livewire;
use Modules\Achievements\Filament\Pages\AchievementsSettingsPage;
use Tests\Support\Modules\ModuleTestCase;

final class KnowledgeGuidesTest extends ModuleTestCase
{
    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    public function test_the_module_guides_are_valid(): void
    {
        $this->assertModuleKnowledgeGuidesAreValid('achievements');
    }

    public function test_every_guide_the_pages_link_exists(): void
    {
        $ids = [];

        foreach ($this->files(__DIR__.'/../../resources/knowledge') as $file) {
            if (preg_match('/^id: (\S+)$/m', (string) file_get_contents($file), $match) === 1) {
                $ids[] = $match[1];
            }
        }

        $linked = [];

        foreach ([__DIR__.'/../../resources/js', __DIR__.'/../../src'] as $folder) {
            foreach ($this->files($folder) as $file) {
                preg_match_all("/(?:guide=\"|knowledgeGuideAction\\(')(achievements\\.[a-z.-]+)/", (string) file_get_contents($file), $matches);
                $linked = [...$linked, ...$matches[1]];
            }
        }

        $this->assertContains('achievements.panel.settings', $linked);
        $this->assertContains('achievements.site.points', $linked);
        $this->assertContains('achievements.site.privacy', $linked);
        $this->assertContains('achievements.site.challenges', $linked);

        foreach (array_unique($linked) as $id) {
            $this->assertContains($id, $ids, $id);
        }
    }

    public function test_the_settings_page_opens_its_guide(): void
    {
        $this->registerOnAdminPanel(pages: [AchievementsSettingsPage::class]);
        $this->artisanCommand('knowledge:sync')->assertSuccessful()->run();

        $this->actingAs($this->userWith(AchievementsSettingsPage::PERMISSION));

        Livewire::test(AchievementsSettingsPage::class)->assertActionVisible('contextualHelp');
    }

    /**
     * @return list<string>
     */
    private function files(string $folder): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
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
