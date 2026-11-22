<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature;

use App\Application\Authorization\Services\PermissionRegistryInterface;
use Tests\Support\Modules\ModuleTestCase;

final class PermissionsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    public function test_enabling_the_module_registers_its_permissions_with_the_module_prefix(): void
    {
        $registry = $this->app->make(PermissionRegistryInterface::class);

        foreach (['view_any', 'manage', 'grant', 'configure'] as $action) {
            $this->assertTrue($registry->has('achievements:achievements.'.$action), $action);
        }
    }

    public function test_the_labels_are_translated_and_grouped_under_the_module(): void
    {
        $labels = [];

        foreach ($this->app->make(PermissionRegistryInterface::class)->forModule('achievements') as $permission) {
            $labels[$permission->key] = $permission->label;
            $this->assertSame('Logros', $permission->group);
        }

        $this->assertSame([
            'achievements:achievements.view_any' => 'Ver los logros en el panel',
            'achievements:achievements.manage' => 'Gestionar el catálogo, los retos y los tipos de reconocimiento',
            'achievements:achievements.grant' => 'Conceder y retirar logros',
            'achievements:achievements.configure' => 'Configurar los logros',
        ], $labels);
    }

    public function test_granting_and_managing_warn_that_bulk_grants_show_who_took_part(): void
    {
        $descriptions = [];

        foreach ($this->app->make(PermissionRegistryInterface::class)->forModule('achievements') as $permission) {
            $descriptions[$permission->key] = (string) $permission->description;
        }

        // Both see who holds an achievement, so who got it in bulk (AchievementPolicy::seesGrants())
        $this->assertStringContainsString('quién participó', $descriptions['achievements:achievements.grant']);
        $this->assertStringContainsString('quién participó', $descriptions['achievements:achievements.manage']);
    }

    public function test_only_managing_is_suggested_for_the_content_role(): void
    {
        $suggested = [];

        foreach ($this->app->make(PermissionRegistryInterface::class)->forModule('achievements') as $permission) {
            if (in_array('editor', $permission->defaultRoles, true)) {
                $suggested[] = $permission->key;
            }
        }

        $this->assertSame(['achievements:achievements.manage'], $suggested);
    }
}
