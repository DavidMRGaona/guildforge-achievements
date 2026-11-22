<?php

declare(strict_types=1);

namespace Modules\Achievements;

use App\Application\Media\Contracts\MediaUsageSourceInterface;
use App\Application\Modules\DTOs\ModuleRouteDTO;
use App\Application\Modules\DTOs\PagePrefixDTO;
use App\Application\Modules\DTOs\PermissionDTO;
use App\Application\Profiles\Contracts\ProfileBadgeSourceInterface;
use App\Application\Profiles\Contracts\ProfileSectionInterface;
use App\Application\Profiles\Contracts\ProfileStatInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Application\SiteBlocks\Contracts\SiteBlockInterface;
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
use Livewire\Livewire;
use Modules\Achievements\Application\Services\AchievementCatalogueInterface;
use Modules\Achievements\Application\Services\AchievementEvaluatorInterface;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Application\Services\AchievementShowcaseInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\ActivityLedgerInterface;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Application\Services\BulkGrantInterface;
use Modules\Achievements\Application\Services\ChallengeBoardInterface;
use Modules\Achievements\Application\Services\ChallengeEvaluatorInterface;
use Modules\Achievements\Application\Services\CommunityMetricsInterface;
use Modules\Achievements\Application\Services\StarterCatalogueInterface;
use Modules\Achievements\Console\Commands\InstallStarterCatalogueCommand;
use Modules\Achievements\Console\Commands\RecalculateAchievementFactsCommand;
use Modules\Achievements\Domain\Events\AchievementFactsChanged;
use Modules\Achievements\Domain\Events\AchievementGranted;
use Modules\Achievements\Domain\Events\AchievementRevoked;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Domain\Repositories\ChallengeRepositoryInterface;
use Modules\Achievements\Filament\Livewire\AccountAchievements;
use Modules\Achievements\Filament\Livewire\AccountGrantLog;
use Modules\Achievements\Filament\Media\AchievementBadgeImageMediaSource;
use Modules\Achievements\Filament\Pages\AchievementsSettingsPage;
use Modules\Achievements\Filament\SiteBlocks\ChallengeSiteBlock;
use Modules\Achievements\Filament\Support\AccountAchievementsHook;
use Modules\Achievements\Infrastructure\Activity\ReaderActivityMetrics;
use Modules\Achievements\Infrastructure\Catalogue\AchievementCatalogue;
use Modules\Achievements\Infrastructure\Catalogue\StarterCatalogue;
use Modules\Achievements\Infrastructure\Challenges\ChallengeBoard;
use Modules\Achievements\Infrastructure\Challenges\ChallengeEvaluator;
use Modules\Achievements\Infrastructure\Community\LedgerCommunityMetrics;
use Modules\Achievements\Infrastructure\Grants\AchievementEvaluator;
use Modules\Achievements\Infrastructure\Grants\AchievementGranter;
use Modules\Achievements\Infrastructure\Grants\BulkGranter;
use Modules\Achievements\Infrastructure\Observers\CountChangedChallenge;
use Modules\Achievements\Infrastructure\Observers\EvaluateChangedAchievements;
use Modules\Achievements\Infrastructure\Observers\ForgetFeaturedWhenMadeSecret;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\ChallengeModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories\EloquentAchievementFactRepository;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories\EloquentAchievementGrantRepository;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories\EloquentAchievementPreferencesRepository;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories\EloquentAchievementRepository;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories\EloquentChallengeRepository;
use Modules\Achievements\Infrastructure\Profiles\AchievementsCountProfileStat;
use Modules\Achievements\Infrastructure\Profiles\AchievementShowcase;
use Modules\Achievements\Infrastructure\Profiles\AchievementsProfileSection;
use Modules\Achievements\Infrastructure\Profiles\FeaturedAchievementBadges;
use Modules\Achievements\Infrastructure\Rarity\CachedAchievementRarity;
use Modules\Achievements\Infrastructure\Services\ActivityLedger;
use Modules\Achievements\Infrastructure\Settings\ModuleAchievementsSettings;
use Modules\Achievements\Infrastructure\Support\AchievementWarnings;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Jobs\EvaluateChallengesJob;
use Modules\Achievements\Jobs\RefreshAchievementRarityJob;
use Modules\Achievements\Listeners\CountChallengesForChangedFacts;
use Modules\Achievements\Listeners\EvaluateAchievementsForChangedFacts;
use Modules\Achievements\Listeners\EvaluateAchievementsOfEnabledModule;
use Modules\Achievements\Listeners\ForgetAnonymizedAccountContributions;
use Modules\Achievements\Listeners\ForgetAnonymizedAccountFacts;
use Modules\Achievements\Listeners\ForgetAnonymizedAccountGrants;
use Modules\Achievements\Listeners\ForgetAnonymizedAccountPreferences;
use Modules\Achievements\Listeners\ForgetCommunityProgress;
use Modules\Achievements\Listeners\RecordActivityFacts;
use Modules\Achievements\Listeners\RefreshRarityAfterGrant;
use Modules\Achievements\Policies\AchievementPolicy;
use Modules\Achievements\Policies\ChallengePolicy;

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
        $this->app->bind(CommunityMetricsInterface::class, LedgerCommunityMetrics::class);
        $this->app->bind(AchievementCatalogueInterface::class, AchievementCatalogue::class);
        $this->app->bind(StarterCatalogueInterface::class, StarterCatalogue::class);
        $this->app->singleton(AchievementWarnings::class);

        // What profiles show: the choices, the catalogue and the rarity, read once per request or job
        $this->app->bind(AchievementPreferencesRepositoryInterface::class, EloquentAchievementPreferencesRepository::class);
        $this->app->scoped(AchievementRarityInterface::class, CachedAchievementRarity::class);
        $this->app->scoped(AchievementShowcaseInterface::class, AchievementShowcase::class);

        // Collective challenges (#12): the count from the ledger and what pages and blocks show
        $this->app->bind(ChallengeRepositoryInterface::class, EloquentChallengeRepository::class);
        $this->app->bind(ChallengeEvaluatorInterface::class, ChallengeEvaluator::class);
        $this->app->scoped(ChallengeBoardInterface::class, ChallengeBoard::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(ActivityRecorded::class, RecordActivityFacts::class);
        Event::listen(UserAnonymized::class, ForgetAnonymizedAccountFacts::class);
        Event::listen(UserAnonymized::class, ForgetAnonymizedAccountGrants::class);
        Event::listen(UserAnonymized::class, ForgetAnonymizedAccountPreferences::class);
        Event::listen(AchievementGranted::class, RefreshRarityAfterGrant::class);
        Event::listen(AchievementRevoked::class, RefreshRarityAfterGrant::class);
        Event::listen(AchievementFactsChanged::class, EvaluateAchievementsForChangedFacts::class);
        Event::listen(AchievementFactsChanged::class, ForgetCommunityProgress::class);
        Event::listen(ModuleEnabled::class, EvaluateAchievementsOfEnabledModule::class);
        Event::listen(AchievementFactsChanged::class, CountChallengesForChangedFacts::class);
        Event::listen(UserAnonymized::class, ForgetAnonymizedAccountContributions::class);

        AchievementModel::observe(EvaluateChangedAchievements::class);
        AchievementModel::observe(ForgetFeaturedWhenMadeSecret::class);
        ChallengeModel::observe(CountChangedChallenge::class);
        RateLimiter::for(self::PROFILE_CHOICES_LIMITER, static fn (Request $request): array => [
            Limit::perMinute(self::PROFILE_CHOICES_PER_MINUTE)->by('achievements-choices:'.self::requesterKey($request)),
            Limit::perHour(self::PROFILE_CHOICES_PER_HOUR)->by('achievements-choices-hour:'.self::requesterKey($request)),
        ]);
        $this->showOnTheUserPage();
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
        return [
            AchievementModel::class => AchievementPolicy::class,
            ChallengeModel::class => ChallengePolicy::class,
        ];
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
            // Who is an association member changes without any fact changing
            $schedule->job(new EvaluateChallengesJob)
                ->dailyAt('04:10')
                ->timezone(app(TimezoneServiceInterface::class)->displayTimezone())
                ->name('achievements:challenges');
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
        return [AchievementsCountProfileStat::class];
    }

    /**
     * A collective challenge with its progress, for the footer, popups and the link page (#12).
     *
     * @return array<class-string<SiteBlockInterface>>
     */
    public function registerSiteBlocks(): array
    {
        return [ChallengeSiteBlock::class];
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
