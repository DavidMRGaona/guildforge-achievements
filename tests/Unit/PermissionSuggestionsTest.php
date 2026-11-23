<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Unit;

use App\Application\Modules\DTOs\PermissionDTO;
use Modules\Achievements\AchievementsServiceProvider;
use Tests\TestCase;

final class PermissionSuggestionsTest extends TestCase
{
    public function test_the_module_registers_its_five_permissions(): void
    {
        $names = array_map(
            fn (PermissionDTO $permission): string => $permission->name,
            (new AchievementsServiceProvider($this->app))->registerPermissions(),
        );

        $this->assertSame([
            'achievements.view_any',
            'achievements.manage',
            'achievements.grant',
            'achievements.moderate_recognitions',
            'achievements.configure',
        ], $names);
    }

    public function test_moderating_recognitions_has_no_role_and_warns_what_it_shows(): void
    {
        $permissions = (new AchievementsServiceProvider($this->app))->registerPermissions();
        $moderate = array_first(array_filter($permissions, fn (PermissionDTO $permission): bool => $permission->name === 'achievements.moderate_recognitions'));

        $this->assertInstanceOf(PermissionDTO::class, $moderate);
        $this->assertSame([], $moderate->roles);
        $this->assertFalse($moderate->adminOnly);
        $this->assertNotNull($moderate->description);
    }

    public function test_only_managing_is_suggested_for_the_content_role(): void
    {
        $suggested = array_filter(
            (new AchievementsServiceProvider($this->app))->registerPermissions(),
            fn (PermissionDTO $permission): bool => in_array('editor', $permission->roles, true),
        );

        $this->assertSame(['achievements.manage'], array_values(array_map(fn (PermissionDTO $permission): string => $permission->name, $suggested)));
    }

    public function test_no_permission_is_admin_only_and_none_is_suggested_for_other_roles(): void
    {
        foreach ((new AchievementsServiceProvider($this->app))->registerPermissions() as $permission) {
            $this->assertFalse($permission->adminOnly, $permission->name);
            $this->assertSame([], array_values(array_diff($permission->roles, ['editor'])), $permission->name);
        }
    }
}
