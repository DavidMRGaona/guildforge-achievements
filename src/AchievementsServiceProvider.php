<?php

declare(strict_types=1);

namespace Modules\Achievements;

use App\Application\Modules\DTOs\PagePrefixDTO;
use App\Application\Modules\DTOs\PermissionDTO;
use App\Modules\ModuleServiceProvider;
use Filament\Pages\Page;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Filament\Pages\AchievementsSettingsPage;
use Modules\Achievements\Infrastructure\Settings\ModuleAchievementsSettings;

/**
 * Achievements («Logros»): what people have done in the association, as badges, levels and
 * recognitions. This version is the base: permissions and the settings page.
 */
final class AchievementsServiceProvider extends ModuleServiceProvider
{
    public function moduleName(): string
    {
        return 'achievements';
    }

    public function register(): void
    {
        parent::register();

        // The switches of the settings page, read once per request or queued job
        $this->app->scoped(AchievementsSettingsInterface::class, ModuleAchievementsSettings::class);
    }

    /**
     * Stored as "achievements:achievements.<action>". Only "manage" is suggested for the editor
     * role (the catalogue, challenges and recognition types are content); granting and revoking
     * achievements and the settings page have no default role: an admin ticks them in the role
     * editor.
     *
     * @return array<PermissionDTO>
     */
    public function registerPermissions(): array
    {
        return [
            new PermissionDTO(
                name: 'achievements.view_any',
                label: __('achievements::messages.permissions.achievements.view_any'),
                group: __('achievements::messages.title'),
                module: $this->moduleName(),
            ),
            new PermissionDTO(
                name: 'achievements.manage',
                label: __('achievements::messages.permissions.achievements.manage'),
                group: __('achievements::messages.title'),
                module: $this->moduleName(),
                roles: ['editor'],
            ),
            new PermissionDTO(
                name: 'achievements.grant',
                label: __('achievements::messages.permissions.achievements.grant'),
                group: __('achievements::messages.title'),
                module: $this->moduleName(),
            ),
            new PermissionDTO(
                name: 'achievements.configure',
                label: __('achievements::messages.permissions.achievements.configure'),
                group: __('achievements::messages.title'),
                module: $this->moduleName(),
            ),
        ];
    }

    /**
     * Module Vue pages are written to resources/js/pages/Achievements/ and rendered as
     * "Achievements/<Page>", inside the core's layout.
     *
     * @return array<PagePrefixDTO>
     */
    public function registerPagePrefixes(): array
    {
        return [
            new PagePrefixDTO(prefix: 'Achievements', module: $this->moduleName(), usesCoreLayout: true),
        ];
    }

    /**
     * @return class-string<Page>
     */
    public function getSettingsPage(): string
    {
        return AchievementsSettingsPage::class;
    }

    /**
     * @return array<class-string<Page>>
     */
    public function registerFilamentPages(): array
    {
        return [AchievementsSettingsPage::class];
    }
}
