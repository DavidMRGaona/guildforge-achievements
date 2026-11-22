<?php

declare(strict_types=1);

namespace Modules\Achievements;

use App\Application\Mail\DTOs\MailCategoryDTO;
use App\Application\Media\Contracts\MediaUsageSourceInterface;
use App\Application\Modules\DTOs\ModuleRouteDTO;
use App\Application\Modules\DTOs\PagePrefixDTO;
use App\Application\Modules\DTOs\PermissionDTO;
use App\Application\Modules\DTOs\SlotRegistrationDTO;
use App\Application\Profiles\Contracts\ProfileBadgeSourceInterface;
use App\Application\Profiles\Contracts\ProfileSectionInterface;
use App\Application\Profiles\Contracts\ProfileStatInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Domain\Activity\Events\ActivityRecorded;
use App\Domain\Events\UserAnonymized;
use App\Domain\Modules\Events\ModuleEnabled;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Infrastructure\Media\Observers\RefreshMediaUsagesObserver;
use App\Modules\ModuleServiceProvider;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Livewire\Livewire;
use Modules\Achievements\Application\Services\AchievementCatalogueInterface;
use Modules\Achievements\Application\Services\AchievementEvaluatorInterface;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Application\Services\AchievementLeaderboardInterface;
use Modules\Achievements\Application\Services\AchievementPointsInterface;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Application\Services\AchievementShowcaseInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\ActivityLedgerInterface;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Application\Services\BulkGrantInterface;
use Modules\Achievements\Application\Services\StarterCatalogueInterface;
use Modules\Achievements\Console\Commands\InstallStarterCatalogueCommand;
use Modules\Achievements\Console\Commands\RecalculateAchievementFactsCommand;
use Modules\Achievements\Domain\Events\AchievementFactsChanged;
use Modules\Achievements\Domain\Events\AchievementGranted;
use Modules\Achievements\Domain\Events\AchievementRevoked;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPointsRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Domain\Repositories\PendingAchievementNoticeRepositoryInterface;
use Modules\Achievements\Filament\Livewire\AccountAchievements;
use Modules\Achievements\Filament\Livewire\AccountGrantLog;
use Modules\Achievements\Filament\Media\AchievementBadgeImageMediaSource;
use Modules\Achievements\Filament\Pages\AchievementsSettingsPage;
use Modules\Achievements\Filament\Support\AccountAchievementsHook;
use Modules\Achievements\Infrastructure\Activity\ReaderActivityMetrics;
use Modules\Achievements\Infrastructure\Catalogue\AchievementCatalogue;
use Modules\Achievements\Infrastructure\Catalogue\StarterCatalogue;
use Modules\Achievements\Infrastructure\Grants\AchievementEvaluator;
use Modules\Achievements\Infrastructure\Grants\AchievementGranter;
use Modules\Achievements\Infrastructure\Grants\BulkGranter;
use Modules\Achievements\Infrastructure\Leaderboard\Leaderboard;
use Modules\Achievements\Infrastructure\Observers\EvaluateChangedAchievements;
use Modules\Achievements\Infrastructure\Observers\ForgetFeaturedWhenMadeSecret;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories\EloquentAchievementFactRepository;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories\EloquentAchievementGrantRepository;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories\EloquentAchievementPointsRepository;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories\EloquentAchievementPreferencesRepository;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories\EloquentAchievementRepository;
use Modules\Achievements\Infrastructure\Points\AchievementPoints;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories\EloquentPendingAchievementNoticeRepository;
use Modules\Achievements\Infrastructure\Profiles\AccountAchievementsTab;
use Modules\Achievements\Infrastructure\Profiles\AchievementsCountProfileStat;
use Modules\Achievements\Infrastructure\Profiles\AchievementShowcase;
use Modules\Achievements\Infrastructure\Profiles\AchievementsLevelProfileStat;
use Modules\Achievements\Infrastructure\Profiles\AchievementsProfileSection;
use Modules\Achievements\Infrastructure\Profiles\FeaturedAchievementBadges;
use Modules\Achievements\Infrastructure\Rarity\CachedAchievementRarity;
use Modules\Achievements\Infrastructure\Services\ActivityLedger;
use Modules\Achievements\Infrastructure\Settings\ModuleAchievementsSettings;
use Modules\Achievements\Infrastructure\Support\AchievementWarnings;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Jobs\RefreshAchievementRarityJob;
use Modules\Achievements\Listeners\EvaluateAchievementsForChangedFacts;
use Modules\Achievements\Listeners\EvaluateAchievementsOfEnabledModule;
use Modules\Achievements\Listeners\ForgetAnonymizedAccountFacts;
use Modules\Achievements\Listeners\ForgetAnonymizedAccountGrants;
use Modules\Achievements\Listeners\ForgetAnonymizedAccountPreferences;
use Modules\Achievements\Listeners\ForgetLeaderboardStandings;
use Modules\Achievements\Listeners\QueueAchievementNotice;
use Modules\Achievements\Listeners\RecordActivityFacts;
use Modules\Achievements\Listeners\RefreshRarityAfterGrant;
use Modules\Achievements\Notifications\AchievementNoticeCategories;
use Modules\Achievements\Policies\AchievementPolicy;

/**
 * Achievements («Logros»): what people have done in the association, as badges, levels and
 * recognitions. It keeps a ledger of what the core's activity sources report (core#774), from
 * which achievements are granted.
 */
final class AchievementsServiceProvider extends ModuleServiceProvider
{
    /** The owner's choices from the profile section (featured badges, hiding them) */
    public const string PROFILE_CHOICES_LIMITER = 'achievements-profile-choices';

    private const int PROFILE_CHOICES_PER_MINUTE = 20;

    private const int PROFILE_CHOICES_PER_HOUR = 200;

    /** Shared props of the account settings' «Logros» tab (#6) */
    public const string ACCOUNT_TAB_PROP = 'achievementsAccount';

    public const string ACCOUNT_TAB_VISIBLE_PROP = 'achievementsAccountVisible';

    private const string ACCOUNT_TAB_ATTRIBUTE = 'achievements.account_tab';

    public function moduleName(): string
    {
        return 'achievements';
    }

    public function register(): void
    {
        parent::register();

        // The switches of the settings page, read once per request or queued job
        $this->app->scoped(AchievementsSettingsInterface::class, ModuleAchievementsSettings::class);

        // The ledger: the service remembers the sources' kinds, so each job or command builds its own
        $this->app->bind(AchievementFactRepositoryInterface::class, EloquentAchievementFactRepository::class);
        $this->app->bind(ActivityLedgerInterface::class, ActivityLedger::class);

        // The catalogue, the grants and the evaluator; what the sources offer is read once per request or job
        $this->app->bind(AchievementRepositoryInterface::class, EloquentAchievementRepository::class);
        $this->app->bind(AchievementGrantRepositoryInterface::class, EloquentAchievementGrantRepository::class);
        $this->app->bind(AchievementGranterInterface::class, AchievementGranter::class);
        $this->app->bind(BulkGrantInterface::class, BulkGranter::class);
        $this->app->scoped(ActivityMetricsInterface::class, ReaderActivityMetrics::class);
        $this->app->bind(AchievementEvaluatorInterface::class, AchievementEvaluator::class);
        $this->app->bind(AchievementCatalogueInterface::class, AchievementCatalogue::class);
        $this->app->bind(StarterCatalogueInterface::class, StarterCatalogue::class);
        $this->app->singleton(AchievementWarnings::class);

        // What profiles show: the choices, the catalogue and the rarity, read once per request or job
        $this->app->bind(AchievementPreferencesRepositoryInterface::class, EloquentAchievementPreferencesRepository::class);
        $this->app->scoped(AchievementRarityInterface::class, CachedAchievementRarity::class);
        $this->app->scoped(AchievementShowcaseInterface::class, AchievementShowcase::class);

        // Points, levels and the leaderboard (#10): each account's points once per request or job
        $this->app->bind(AchievementPointsRepositoryInterface::class, EloquentAchievementPointsRepository::class);
        $this->app->scoped(AchievementPointsInterface::class, AchievementPoints::class);
        $this->app->bind(AchievementLeaderboardInterface::class, Leaderboard::class);

        // Grants waiting to be told to their holders, a little later and together (#6)
        $this->app->bind(PendingAchievementNoticeRepositoryInterface::class, EloquentPendingAchievementNoticeRepository::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(ActivityRecorded::class, RecordActivityFacts::class);
        Event::listen(UserAnonymized::class, ForgetAnonymizedAccountFacts::class);
        Event::listen(UserAnonymized::class, ForgetAnonymizedAccountGrants::class);
        Event::listen(UserAnonymized::class, ForgetAnonymizedAccountPreferences::class);
        Event::listen(AchievementGranted::class, RefreshRarityAfterGrant::class);
        Event::listen(AchievementGranted::class, QueueAchievementNotice::class);
        Event::listen(AchievementRevoked::class, RefreshRarityAfterGrant::class);
        Event::listen(AchievementGranted::class, ForgetLeaderboardStandings::class);
        Event::listen(AchievementRevoked::class, ForgetLeaderboardStandings::class);
        Event::listen(AchievementFactsChanged::class, EvaluateAchievementsForChangedFacts::class);
        Event::listen(ModuleEnabled::class, EvaluateAchievementsOfEnabledModule::class);

        AchievementModel::observe(EvaluateChangedAchievements::class);
        AchievementModel::observe(ForgetFeaturedWhenMadeSecret::class);
        // An achievement's points count for everybody who holds it
        AchievementModel::updated(static function (AchievementModel $achievement): void {
            if ($achievement->wasChanged('points')) {
                app(AchievementLeaderboardInterface::class)->forget();
            }
        });
        RateLimiter::for(self::PROFILE_CHOICES_LIMITER, static fn (Request $request): array => [
            Limit::perMinute(self::PROFILE_CHOICES_PER_MINUTE)->by('achievements-choices:'.self::requesterKey($request)),
            Limit::perHour(self::PROFILE_CHOICES_PER_HOUR)->by('achievements-choices-hour:'.self::requesterKey($request)),
        ]);
        $this->showOnTheUserPage();
        $this->shareAccountTab();
        $this->keepMediaUsagesUpToDate();
        $this->registerSchedule();

        if ($this->app->runningInConsole()) {
            $this->commands([RecalculateAchievementFactsCommand::class, InstallStarterCatalogueCommand::class]);
        }
    }

    /**
     * @return array<class-string, class-string>
     */
    public function registerPolicies(): array
    {
        return [AchievementModel::class => AchievementPolicy::class];
    }

    /**
     * @return array<class-string<MediaUsageSourceInterface>>
     */
    public function registerMediaSources(): array
    {
        return [AchievementBadgeImageMediaSource::class];
    }

    /**
     * The account's achievements and their history, at the end of the core's user page (#4).
     */
    private function showOnTheUserPage(): void
    {
        Livewire::component(AccountAchievements::NAME, AccountAchievements::class);
        Livewire::component(AccountGrantLog::NAME, AccountGrantLog::class);

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_END,
            static fn (): View|string => AccountAchievementsHook::render(),
            scopes: EditUser::class,
        );
    }

    /**
     * The «Logros» tab of the account settings (#6): computed on `/perfil` alone, once per request.
     */
    private function shareAccountTab(): void
    {
        $resolve = static function (): ?array {
            $request = request();
            $user = $request->user();

            if ($request->route()?->getName() !== 'profile.show' || $user === null) {
                return null;
            }

            // Kept on the request, not in the closure: a long-lived process serves many requests
            if (! $request->attributes->has(self::ACCOUNT_TAB_ATTRIBUTE)) {
                $request->attributes->set(self::ACCOUNT_TAB_ATTRIBUTE, app(AccountAchievementsTab::class)->props($user));
            }

            $props = $request->attributes->get(self::ACCOUNT_TAB_ATTRIBUTE);

            return is_array($props) ? $props : null;
        };
        $onProfile = static fn (): bool => request()->route()?->getName() === 'profile.show' && auth()->user() !== null;

        Inertia::share(self::ACCOUNT_TAB_PROP, $resolve);
        Inertia::share(self::ACCOUNT_TAB_VISIBLE_PROP, static fn (): ?bool => $onProfile() ? $resolve() !== null : null);
    }

    /**
     * Creating or deleting an achievement refreshes its badge image's usage in the media library; an
     * update only when the image, the name or the secrecy changed (a usage carries the title).
     */
    private function keepMediaUsagesUpToDate(): void
    {
        $always = new RefreshMediaUsagesObserver(
            AchievementBadgeImageMediaSource::TYPE,
            static fn (Model $model): array => [(string) $model->getKey()],
        );
        $whenShownChanges = new RefreshMediaUsagesObserver(
            AchievementBadgeImageMediaSource::TYPE,
            static fn (Model $model): array => $model->wasChanged(['image_public_id', 'name', 'is_secret']) ? [(string) $model->getKey()] : [],
        );

        AchievementModel::created($always->saved(...));
        AchievementModel::updated($whenShownChanges->saved(...));
        AchievementModel::deleted($always->deleted(...));
    }

    /**
     * Years of account change with the date alone: they are evaluated every night (not silently:
     * these are real anniversaries). Rarity too: people stop being active with the date alone.
     */
    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new EvaluateAchievementsJob(silent: false, onlyAccountYears: true))
                ->daily()
                ->name('achievements:account-years');
            // At night where people are: the display zone, not the server's
            $schedule->job(new RefreshAchievementRarityJob)
                ->dailyAt('03:30')
                ->timezone(app(TimezoneServiceInterface::class)->displayTimezone())
                ->name('achievements:rarity');
        });
    }

    /**
     * The signed-in account (the route needs one), else the network address.
     */
    private static function requesterKey(Request $request): string
    {
        $user = $request->user();

        return $user === null ? 'ip:'.$request->ip() : 'user:'.$user->getAuthIdentifier();
    }

    /**
     * The notices of the bell (#6): what one just got, and others' rare achievements.
     *
     * @return list<MailCategoryDTO>
     */
    public function registerMailCategories(): array
    {
        return AchievementNoticeCategories::all();
    }

    /**
     * The «Logros» tab of the account settings (#6), shown while one of its choices is offered.
     *
     * @return array<SlotRegistrationDTO>
     */
    public function registerSlots(): array
    {
        return [
            new SlotRegistrationDTO(
                slot: 'profile-sections',
                component: 'components/profile/AchievementsAccountTab.vue',
                module: $this->moduleName(),
                order: 60,
                // visibleKey is read from the slot's props, so it is listed too
                dataKeys: [self::ACCOUNT_TAB_PROP, self::ACCOUNT_TAB_VISIBLE_PROP],
                profileTab: [
                    'tabId' => AccountAchievementsTab::TAB,
                    'icon' => 'trophy',
                    'labelKey' => 'achievements.account.tabLabel',
                    'visibleKey' => self::ACCOUNT_TAB_VISIBLE_PROP,
                ],
            ),
        ];
    }

    /**
     * @return array<class-string<ProfileSectionInterface>>
     */
    public function registerProfileSections(): array
    {
        return [AchievementsProfileSection::class];
    }

    /**
     * @return array<class-string<ProfileStatInterface>>
     */
    public function registerProfileStats(): array
    {
        return [AchievementsCountProfileStat::class, AchievementsLevelProfileStat::class];
    }

    /**
     * @return array<class-string<ProfileBadgeSourceInterface>>
     */
    public function registerProfileBadgeSources(): array
    {
        return [FeaturedAchievementBadges::class];
    }

    /**
     * The catalogue page, for the menu editor (Sitio web → Navegación). The link itself is added
     * once, when the module is first enabled (AchievementsMenuLinkSeeder).
     *
     * @return array<ModuleRouteDTO>
     */
    public function registerRoutes(): array
    {
        return [
            new ModuleRouteDTO(
                routeName: 'achievements.index',
                label: __('achievements::messages.title'),
                module: $this->moduleName(),
            ),
            new ModuleRouteDTO(
                routeName: 'achievements.leaderboard',
                label: __('achievements::messages.leaderboard.title'),
                module: $this->moduleName(),
            ),
        ];
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
                description: __('achievements::messages.permissions.achievements.manage_description'),
                module: $this->moduleName(),
                roles: ['editor'],
            ),
            new PermissionDTO(
                name: 'achievements.grant',
                label: __('achievements::messages.permissions.achievements.grant'),
                group: __('achievements::messages.title'),
                description: __('achievements::messages.permissions.achievements.grant_description'),
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
