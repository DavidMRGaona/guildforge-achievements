<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Pages;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Concerns\ChecksPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\ValueObjects\LevelScale;
use Modules\Achievements\Domain\ValueObjects\MetricKinds;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Jobs\EvaluateChallengesJob;
use Modules\Achievements\Jobs\ListChallengeCompletersJob;

/**
 * «Sistema → Configuración → Logros»: the switch of every mechanic of the module.
 *
 * A mechanic that is off is hidden, its routes answer 404 and its rules are not evaluated; what it
 * already granted stays. The values are read through AchievementsSettingsInterface, which is what
 * the rest of the module asks. Switching a kind of activity, the secret achievements or the
 * community achievements back on evaluates everybody again, silently, from the ledger.
 *
 * @property Schema $form
 */
final class AchievementsSettingsPage extends Page implements HasForms
{
    use ChecksPermissions;
    use InteractsWithForms;

    public const string PERMISSION = 'achievements:achievements.configure';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $cluster = SettingsCluster::class;

    // Alphabetical between «Inscripciones» (127) and «Mesas» (130)
    protected static ?int $navigationSort = 128;

    protected static ?string $slug = 'logros';

    protected string $view = 'filament.pages.simple-settings';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('achievements::messages.settings.navigation');
    }

    public function getTitle(): string
    {
        return __('achievements::messages.settings.title');
    }

    public static function canAccess(): bool
    {
        return self::userCan(self::PERMISSION);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $service = $this->service();
        // Only the kinds the form offers: a stored kind that is not one of them is never a field
        $kinds = [];

        foreach (MetricKinds::ALL as $kind) {
            $kinds[$kind] = $service->metricKindEnabled($kind);
        }

        // The scale in use, the starter one too, so it can be edited from its names
        $this->form->fill([
            'metric_kinds' => $kinds,
            'levels_scale' => $service->levelScale()->toArray(),
        ] + $service->settings()->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('achievements::messages.settings.sections.achievements'))
                    ->description(__('achievements::messages.settings.sections.achievements_description'))
                    ->schema([
                        $this->toggle('progress'),
                        $this->toggle('rarity'),
                        $this->toggle('secrets'),
                        $this->toggle('seasons_and_streaks'),
                        $this->toggle('community'),
                        $this->toggle('bulk_grants'),
                        $this->toggle('challenges'),
                    ])
                    ->columns(2),

                Section::make(__('achievements::messages.settings.sections.announcements'))
                    ->description(__('achievements::messages.settings.sections.announcements_description'))
                    ->schema([
                        $this->toggle('announcements')->live(),
                        TextInput::make('announcements_rarity_threshold')
                            ->label(__('achievements::messages.settings.fields.announcements_rarity_threshold'))
                            ->helperText(__('achievements::messages.settings.helpers.announcements_rarity_threshold'))
                            ->numeric()
                            ->minValue(AchievementsSettings::MIN_ANNOUNCEMENT_THRESHOLD)
                            ->maxValue(AchievementsSettings::MAX_ANNOUNCEMENT_THRESHOLD)
                            ->step(0.1)
                            ->suffix('%')
                            ->required()
                            ->visible(fn (Get $get): bool => (bool) $get('announcements')),
                    ])
                    ->columns(2),

                Section::make(__('achievements::messages.settings.sections.gamification'))
                    ->description(__('achievements::messages.settings.sections.gamification_description'))
                    ->schema([
                        $this->toggle('points'),
                        $this->toggle('levels')->live(),
                        $this->toggle('leaderboard'),
                        Repeater::make('levels_scale')
                            ->label(__('achievements::messages.settings.fields.levels_scale'))
                            ->helperText(__('achievements::messages.settings.helpers.levels_scale'))
                            ->schema([
                                TextInput::make('threshold')
                                    ->label(__('achievements::messages.settings.fields.level_threshold'))
                                    ->integer()
                                    ->minValue(0)
                                    ->maxValue(LevelScale::MAX_THRESHOLD)
                                    ->suffix(__('achievements::messages.settings.points_suffix'))
                                    ->required(),
                                TextInput::make('name')
                                    ->label(__('achievements::messages.settings.fields.level_name'))
                                    ->maxLength(LevelScale::NAME_MAX_LENGTH)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->minItems(LevelScale::MIN_LEVELS)
                            ->maxItems(LevelScale::MAX_LEVELS)
                            ->reorderable(false)
                            ->addActionLabel(__('achievements::messages.settings.add_level'))
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => (bool) $get('levels')),
                    ])
                    ->columns(2),

                Section::make(__('achievements::messages.settings.sections.recognitions'))
                    ->description(__('achievements::messages.settings.sections.recognitions_description'))
                    ->schema([
                        $this->toggle('recognitions')->live(),
                        TextInput::make('recognitions_window_days')
                            ->label(__('achievements::messages.settings.fields.recognitions_window_days'))
                            ->helperText(__('achievements::messages.settings.helpers.recognitions_window_days'))
                            ->integer()
                            ->minValue(AchievementsSettings::MIN_RECOGNITION_WINDOW_DAYS)
                            ->maxValue(AchievementsSettings::MAX_RECOGNITION_WINDOW_DAYS)
                            ->suffix(__('achievements::messages.settings.days'))
                            ->required()
                            ->visible(fn (Get $get): bool => (bool) $get('recognitions')),
                    ])
                    ->columns(2),

                Section::make(__('achievements::messages.settings.sections.metric_kinds'))
                    ->description(__('achievements::messages.settings.sections.metric_kinds_description'))
                    // Only the kinds of the module, never a key that was stored
                    ->schema(array_map(
                        fn (string $kind): Toggle => Toggle::make('metric_kinds.'.$kind)
                            ->label(__('achievements::messages.settings.metric_kinds.'.$kind)),
                        MetricKinds::ALL,
                    ))
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);

        $state = $this->form->getState();
        $current = $this->service()->settings();

        $saved = new AchievementsSettings(
            progress: (bool) ($state['progress'] ?? $current->progress),
            rarity: (bool) ($state['rarity'] ?? $current->rarity),
            secrets: (bool) ($state['secrets'] ?? $current->secrets),
            seasonsAndStreaks: (bool) ($state['seasons_and_streaks'] ?? $current->seasonsAndStreaks),
            community: (bool) ($state['community'] ?? $current->community),
            bulkGrants: (bool) ($state['bulk_grants'] ?? $current->bulkGrants),
            announcements: (bool) ($state['announcements'] ?? $current->announcements),
            // A field that is hidden while its switch is off is not in the state: the saved value stays
            announcementsRarityThreshold: is_numeric($state['announcements_rarity_threshold'] ?? null)
                ? (float) $state['announcements_rarity_threshold']
                : $current->announcementsRarityThreshold,
            points: (bool) ($state['points'] ?? $current->points),
            levels: (bool) ($state['levels'] ?? $current->levels),
            leaderboard: (bool) ($state['leaderboard'] ?? $current->leaderboard),
            levelScale: $this->submittedScale($state['levels_scale'] ?? null, $current),
            recognitions: (bool) ($state['recognitions'] ?? $current->recognitions),
            recognitionsWindowDays: is_numeric($state['recognitions_window_days'] ?? null)
                ? (int) $state['recognitions_window_days']
                : $current->recognitionsWindowDays,
            challenges: (bool) ($state['challenges'] ?? $current->challenges),
            metricKinds: $this->submittedKinds($state['metric_kinds'] ?? null, $current),
        );

        $this->service()->save($saved);

        // What was switched off was not evaluated meanwhile: the ledger kept its facts, so evaluating
        // everybody again (silently) grants what was met while it was off
        if ($this->switchedBackOn($current, $saved)) {
            EvaluateAchievementsJob::dispatch();
        }

        // Challenges were not counted while off (#12): counted now, with what the ledger holds
        if ($saved->challenges && (! $current->challenges || $this->switchedBackOn($current, $saved))) {
            EvaluateChallengesJob::dispatch();
        }

        // And their lists were not made meanwhile: made once now, after that count, not on the 1st
        if ($saved->challenges && ! $current->challenges) {
            ListChallengeCompletersJob::dispatch(catchUp: true)->delay(now()->addMinutes(ListChallengeCompletersJob::CATCH_UP_DELAY_MINUTES));
        }

        Notification::make()
            ->title(__('achievements::messages.settings.saved'))
            ->success()
            ->send();
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('achievements::messages.settings.save'))
                ->submit('save'),
        ];
    }

    private function toggle(string $name): Toggle
    {
        return Toggle::make($name)
            ->label(__('achievements::messages.settings.fields.'.$name))
            ->helperText(__('achievements::messages.settings.helpers.'.$name));
    }

    /**
     * The scale on the form, in the order typed; while it is hidden (levels off) the stored one
     * stays. A scale that is not one (the first level above 0, a level that needs no more points
     * than the one before) is refused on the field. The starter scale as it is gives null.
     *
     * @throws ValidationException
     */
    private function submittedScale(mixed $submitted, AchievementsSettings $current): ?LevelScale
    {
        if (! is_array($submitted)) {
            return $current->levelScale;
        }

        $levels = [];

        foreach (array_values($submitted) as $level) {
            $threshold = is_array($level) ? ($level['threshold'] ?? null) : null;
            $name = is_array($level) ? ($level['name'] ?? null) : null;

            if (! is_numeric($threshold) || ! is_string($name)) {
                throw ValidationException::withMessages(['data.levels_scale' => __('achievements::messages.settings.levels_invalid')]);
            }

            $levels[] = ['threshold' => (int) $threshold, 'name' => $name];
        }

        try {
            $scale = new LevelScale($levels);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['data.levels_scale' => __('achievements::messages.settings.levels_invalid')]);
        }

        // The starter scale untouched is not stored: its names keep following the site's language,
        // whatever language the panel shows them in
        foreach (array_unique([app()->getLocale(), (string) config('app.locale'), (string) config('app.fallback_locale')]) as $locale) {
            if ($scale->toArray() === AchievementsSettings::starterLevelScale($locale)->toArray()) {
                return null;
            }
        }

        return $scale;
    }

    /**
     * The kinds on the form win; the ones it does not list (a kind of a newer core, say) keep their stored switch.
     *
     * @return array<string, bool>
     */
    private function submittedKinds(mixed $submitted, AchievementsSettings $current): array
    {
        $kinds = $current->metricKinds;

        foreach (is_array($submitted) ? $submitted : [] as $kind => $enabled) {
            if (is_string($kind) && MetricKinds::isKnown($kind)) {
                $kinds[$kind] = (bool) $enabled;
            }
        }

        return $kinds;
    }

    private function switchedBackOn(AchievementsSettings $before, AchievementsSettings $after): bool
    {
        if ((! $before->secrets && $after->secrets) || (! $before->seasonsAndStreaks && $after->seasonsAndStreaks) || (! $before->community && $after->community) || (! $before->recognitions && $after->recognitions)) {
            return true;
        }

        return array_any(MetricKinds::ALL, fn ($kind) => ! $before->metricKindEnabled($kind) && $after->metricKindEnabled($kind));
    }

    private function service(): AchievementsSettingsInterface
    {
        return app(AchievementsSettingsInterface::class);
    }
}
