<?php

declare(strict_types=1);

namespace Modules\Achievements;

use App\Application\Calendar\Contracts\CalendarAttendanceSourceInterface;
use App\Application\Calendar\Contracts\CalendarEntrySourceInterface;
use App\Application\Modules\DTOs\ModuleRouteDTO;
use App\Application\Modules\DTOs\NavigationItemDTO;
use App\Application\Modules\DTOs\PagePrefixDTO;
use App\Application\Modules\DTOs\PermissionDTO;
use App\Application\Modules\DTOs\SlotRegistrationDTO;
use App\Application\Proposals\Contracts\EventProposalRequestInterface;
use App\Application\Registration\Contracts\RegistrationFieldInterface;
use App\Application\Surveys\Contracts\SurveySubjectSourceInterface;
use App\Modules\ModuleServiceProvider;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Widgets\Widget;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Filament\Pages\AchievementsSettingsPage;
use Modules\Achievements\Infrastructure\Settings\ModuleAchievementsSettings;

final class AchievementsServiceProvider extends ModuleServiceProvider
{
    /**
     * Get the module name (kebab-case).
     */
    public function moduleName(): string
    {
        return 'achievements';
    }

    /**
     * Register module services.
     *
     * IMPORTANT: Register model extensions (macros) here, not in boot().
     * This ensures they're available before Filament loads Resources.
     */
    public function register(): void
    {
        parent::register();

        // Register model extensions (macros) early
        // $this->registerModelExtensions();

        // config/module.php is loaded by parent::register() into "modules.achievements"

        // The switches of the settings page, read once per request or queued job
        $this->app->scoped(AchievementsSettingsInterface::class, ModuleAchievementsSettings::class);
    }

    /**
     * Bootstrap module services.
     */
    public function boot(): void
    {
        parent::boot();

        // Register Livewire components (required for Filament RelationManagers)
        // $this->registerLivewireComponents();

        // Extend core Filament Resources with RelationManagers
        // $this->registerFilamentExtensions();

        // Register domain event listeners
        // $this->registerEventListeners();
    }

    // ============================================================
    // EXTENDING CORE FUNCTIONALITY (uncomment and adapt as needed)
    // ============================================================

    /**
     * Register dynamic relationships on core models.
     * Uses resolveRelationUsing() - the correct Laravel way to add relationships dynamically.
     * Called in register() to ensure relationships are available before Filament loads.
     *
     * Example: Add an 'items' relationship to a core model:
     *
     *     private function registerModelExtensions(): void
     *     {
     *         // resolveRelationUsing is idempotent - calling multiple times is safe
     *         CoreModel::resolveRelationUsing('items', function (CoreModel $model) {
     *             return $model->hasMany(ItemModel::class, 'core_id', 'id');
     *         });
     *     }
     *
     * Note: Don't use Model::macro() for relationships - it forwards to Query Builder.
     */

    /**
     * Register Livewire components from the module.
     * Required for Filament RelationManagers to work properly.
     *
     * Example:
     *
     *     private function registerLivewireComponents(): void
     *     {
     *         if (! class_exists(\Livewire\Livewire::class)) {
     *             return;
     *         }
     *
     *         \Livewire\Livewire::component(
     *             'modules.achievements.filament.relation-managers.items-relation-manager',
     *             ItemsRelationManager::class
     *         );
     *     }
     */

    /**
     * Extend core Filament Resources with this module's RelationManagers.
     * Uses the HasExtendableRelations trait on core Resources.
     *
     * Example:
     *
     *     private function registerFilamentExtensions(): void
     *     {
     *         if (class_exists(\App\Filament\Resources\EventResource::class)) {
     *             \App\Filament\Resources\EventResource::extendRelations([
     *                 ItemsRelationManager::class,
     *             ]);
     *         }
     *     }
     */

    /**
     * Register domain event listeners.
     *
     * Example:
     *
     *     private function registerEventListeners(): void
     *     {
     *         \Illuminate\Support\Facades\Event::listen(
     *             ItemCreated::class,
     *             SendItemCreatedNotification::class
     *         );
     *     }
     */

    /**
     * Register policies provided by this module.
     *
     * Policies created by module:make-filament-resource stay inactive until listed here:
     *
     *     return [ItemModel::class => ItemPolicy::class];
     *
     * @return array<class-string, class-string> Map of model class to policy class
     */
    public function registerPolicies(): array
    {
        return [];
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
     * Register navigation items provided by this module.
     *
     * @return array<NavigationItemDTO>
     */
    public function registerNavigation(): array
    {
        return [];
    }

    /**
     * Register slot components provided by this module.
     * Slots allow modules to inject Vue components into layout positions.
     *
     * @return array<SlotRegistrationDTO>
     */
    public function registerSlots(): array
    {
        return [];
    }

    /**
     * Register public routes for menu item configuration.
     * Allows module routes to appear in the menu item dropdown.
     *
     * @return array<ModuleRouteDTO>
     */
    public function registerRoutes(): array
    {
        return [];
    }

    /**
     * Register page prefixes provided by this module.
     * Allows module Vue pages to be resolved by Inertia: module:make-vue-page writes
     * pages to resources/js/pages/Achievements/, rendered as "Achievements/<Page>".
     * With usesCoreLayout the core renders its layout around them: pages never wrap their own.
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
     * Register Filament widgets this module adds to the dashboard.
     * Widgets created by module:make-widget only appear once listed here;
     * admins can then toggle and order them on the dashboard settings page.
     *
     * @return array<class-string<Widget>>
     */
    public function registerFilamentWidgets(): array
    {
        return [];
    }

    /**
     * Register Filament pages provided by this module (resources are discovered
     * from src/Filament/Resources).
     *
     * @return array<class-string<Page>>
     */
    public function registerFilamentPages(): array
    {
        return [AchievementsSettingsPage::class];
    }

    /**
     * Register calendar sources provided by this module.
     * Allows module entries to appear in the public calendars.
     *
     * @return array<class-string<CalendarEntrySourceInterface>>
     */
    public function registerCalendarSources(): array
    {
        return [];
    }

    /**
     * Register calendar attendance sources provided by this module.
     * Tells the core which calendar entries each person is confirmed in or runs (personal calendar).
     *
     * @return array<class-string<CalendarAttendanceSourceInterface>>
     */
    public function registerCalendarAttendanceSources(): array
    {
        return [];
    }

    /**
     * Register survey subject sources provided by this module.
     * Allows module records to be surveyed when the surveys module is installed.
     *
     * @return array<class-string<SurveySubjectSourceInterface>>
     */
    public function registerSurveySubjectSources(): array
    {
        return [];
    }

    /**
     * Register the requests this module adds to event proposals made from the web.
     * The core draws their declarative fields, checks the values and calls apply() when the
     * proposal is approved. Keys start with "achievements.". See docs/modules/proposal-requests.md.
     *
     * @return array<class-string<EventProposalRequestInterface>>
     */
    public function registerEventProposalRequests(): array
    {
        return [];
    }

    /**
     * Register the optional fields this module adds to the registration form (checkboxes, always
     * unticked). The core checks the values and dispatches RegistrationFieldsSubmitted once the
     * account exists. Keys start with "achievements.". See docs/modules/registration-fields.md.
     *
     * @return array<class-string<RegistrationFieldInterface>>
     */
    public function registerRegistrationFields(): array
    {
        return [];
    }

    /**
     * The module's settings page in the «Configuración» cluster, guarded by a permission of the
     * module. List it in registerFilamentPages() too; «Configuración» in Módulos opens it.
     *
     * @return class-string<Page>
     */
    public function getSettingsPage(): string
    {
        return AchievementsSettingsPage::class;
    }

    /**
     * Fallback only: the form of the core's generic module settings page, used while
     * getSettingsPage() declares no page of the module's own.
     *
     * @return array<Component>
     */
    public function getSettingsSchema(): array
    {
        return [];
    }
}
