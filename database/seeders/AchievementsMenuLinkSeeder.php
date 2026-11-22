<?php

declare(strict_types=1);

namespace Modules\Achievements\Database\Seeders;

use App\Domain\Navigation\Entities\MenuItem;
use App\Domain\Navigation\Enums\LinkTarget;
use App\Domain\Navigation\Enums\MenuLocation;
use App\Domain\Navigation\Enums\MenuVisibility;
use App\Domain\Navigation\Repositories\MenuItemRepositoryInterface;
use App\Domain\Navigation\ValueObjects\MenuItemId;
use Illuminate\Database\Seeder;

/**
 * Adds «Logros» at the end of the header menu when the module is first enabled. It carries the
 * module's name, so the core hides it while the module is disabled and removes it on uninstall;
 * admins move or delete it in Sitio web → Navegación. Never added twice, and never again once
 * deleted (seeders run once).
 */
final class AchievementsMenuLinkSeeder extends Seeder
{
    public const string ROUTE = 'achievements.index';

    public function run(): void
    {
        $menu = app(MenuItemRepositoryInterface::class);

        foreach ($menu->all() as $item) {
            if ($item->route() === self::ROUTE) {
                return;
            }
        }

        $menu->save(new MenuItem(
            id: MenuItemId::generate(),
            location: MenuLocation::Header,
            parentId: null,
            label: __('achievements::messages.title'),
            url: null,
            route: self::ROUTE,
            routeParams: [],
            icon: 'heroicon-o-trophy',
            target: LinkTarget::Self,
            visibility: MenuVisibility::Public,
            permissions: [],
            sortOrder: $menu->maxSortOrder(MenuLocation::Header) + 10,
            isActive: true,
            module: 'achievements',
        ));
    }
}
