<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Support;

use App\Domain\Activity\ActivityRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\MetricType;
use Modules\Achievements\Domain\Rules\RuleWindow;
use Modules\Achievements\Domain\Rules\WindowType;
use Modules\Achievements\Domain\Rules\YearsBasis;
use Modules\Achievements\Domain\ValueObjects\MetricKinds;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementGrantModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\RecognitionTypeModel;

/**
 * The rule builder of the achievement form: flat fields (`rule_*`) that the pages turn into the
 * stored rule with AchievementRule, and back. Kinds come from the sources offered now (plus the
 * kind of the rule being edited, marked «sin fuente»); attributes and their values from the sources
 * of the kind chosen (OffersActivityAttributes), plus the edited rule's own. A combination
 * AchievementRule cannot read is refused on save.
 *
 * The window (when it counts: always, per season, or months in a row) is offered while seasons and
 * streaks are on, and always on a rule that already has one, so it can be seen and taken off; a
 * streak only for «Número de actividades», and years and people met never have one.
 */
final class AchievementRuleForm
{
    public const string METRIC = 'rule_metric';

    public const string KIND = 'rule_kind';

    public const string ROLE = 'rule_role';

    public const string WITH_ROLE = 'rule_with_role';

    public const string ATTRIBUTE_KEY = 'rule_attribute_key';

    public const string ATTRIBUTE_VALUE = 'rule_attribute_value';

    public const string PLACEMENT_MAX = 'rule_placement_max';

    public const string DISTINCT_KEY = 'rule_distinct_key';

    public const string BASIS = 'rule_basis';

    public const string THRESHOLD = 'rule_threshold';

    public const string RECOGNITION_TYPE = 'rule_recognition_type';

    public const string WINDOW = 'rule_window';

    public const string STREAK_MONTHS = 'rule_streak_months';

    public const string STREAK_PER_SEASON = 'rule_streak_per_season';

    /** @var list<string> */
    public const array FIELDS = [
        self::METRIC, self::KIND, self::ROLE, self::WITH_ROLE, self::ATTRIBUTE_KEY, self::ATTRIBUTE_VALUE,
        self::PLACEMENT_MAX, self::DISTINCT_KEY, self::BASIS, self::THRESHOLD,
        self::WINDOW, self::STREAK_MONTHS, self::STREAK_PER_SEASON, self::RECOGNITION_TYPE,
    ];

    /**
     * @return list<Select|TextInput|Toggle>
     */
    public static function components(): array
    {
        $activity = static fn (Get $get): bool => in_array($get(self::METRIC), [MetricType::Count->value, MetricType::Distinct->value], true);
        $streak = static fn (Get $get): bool => $get(self::METRIC) === MetricType::Count->value && $get(self::WINDOW) === WindowType::Streak->value;
        $people = static fn (Get $get): bool => $get(self::METRIC) === MetricType::People->value;
        $withKind = static fn (Get $get): bool => $activity($get) || $people($get);
        $recognitions = static fn (Get $get): bool => $get(self::METRIC) === MetricType::Recognitions->value;

        return [
            Select::make(self::METRIC)
                ->label(self::text('fields.metric'))
                ->helperText(self::text('helpers.metric'))
                ->options(self::metricOptions())
                ->default(MetricType::Count->value)
                ->native(false)
                ->required()
                ->live()
                // A streak only counts activities: another metric falls back to «always», unless the
                // window is locked, which the save then refuses with its own message
                ->afterStateUpdated(static function (?string $state, Get $get, Set $set, ?Model $record): void {
                    if ($get(self::WINDOW) === WindowType::Streak->value && $state !== MetricType::Count->value && ! self::windowLocked($record)) {
                        $set(self::WINDOW, WindowType::AllTime->value);
                        $set(self::STREAK_PER_SEASON, false);
                    }
                }),

            Select::make(self::KIND)
                ->label(self::text('fields.kind'))
                ->helperText(self::text('helpers.kind'))
                ->options(static fn (Get $get, ?Model $record): array => self::kindOptions(self::stored($record)?->kind, communityOnly: $people($get)))
                ->native(false)
                ->required($withKind)
                ->visible($withKind)
                ->live(),

            Select::make(self::ROLE)
                ->label(self::text('fields.role'))
                ->helperText(self::text('helpers.role'))
                ->options(static fn (Get $get): array => $people($get) ? self::peopleRoleOptions() : self::roleOptions())
                ->placeholder(self::text('placeholders.any_role'))
                ->native(false)
                ->visible($withKind),

            Select::make(self::WITH_ROLE)
                ->label(self::text('fields.with_role'))
                ->helperText(self::text('helpers.with_role'))
                ->options(self::peopleRoleOptions())
                ->placeholder(self::text('placeholders.any_role'))
                ->native(false)
                ->visible($people),

            Select::make(self::RECOGNITION_TYPE)
                ->label(self::text('fields.recognition_type'))
                ->helperText(self::text('helpers.recognition_type'))
                ->options(static fn (): array => RecognitionTypeModel::query()->orderBy('name')->pluck('name', 'id')->map(strval(...))->all())
                ->placeholder(self::text('placeholders.any_recognition_type'))
                ->native(false)
                ->visible($recognitions),

            Select::make(self::DISTINCT_KEY)
                ->label(self::text('fields.distinct_key'))
                ->helperText(self::text('helpers.distinct_key'))
                ->options(static fn (Get $get, ?Model $record): array => self::attributeOptions(self::string($get(self::KIND)), self::stored($record)?->distinctKey))
                ->native(false)
                ->required(static fn (Get $get): bool => $get(self::METRIC) === MetricType::Distinct->value)
                ->visible(static fn (Get $get): bool => $get(self::METRIC) === MetricType::Distinct->value),

            Select::make(self::ATTRIBUTE_KEY)
                ->label(self::text('fields.attribute_key'))
                ->helperText(self::text('helpers.attribute_key'))
                ->options(static fn (Get $get, ?Model $record): array => self::attributeOptions(self::string($get(self::KIND)), self::stored($record)?->attributeKey))
                ->placeholder(self::text('placeholders.no_attribute'))
                ->native(false)
                ->visible($activity)
                ->live(),

            Select::make(self::ATTRIBUTE_VALUE)
                ->label(self::text('fields.attribute_value'))
                ->options(static fn (Get $get, ?Model $record): array => self::valueOptions(self::string($get(self::KIND)), self::string($get(self::ATTRIBUTE_KEY)), self::stored($record)?->attributeValue))
                ->searchable()
                ->native(false)
                ->required(static fn (Get $get): bool => $activity($get) && self::string($get(self::ATTRIBUTE_KEY)) !== null)
                ->visible(static fn (Get $get): bool => $activity($get) && self::string($get(self::ATTRIBUTE_KEY)) !== null),

            TextInput::make(self::PLACEMENT_MAX)
                ->label(self::text('fields.placement_max'))
                ->helperText(self::text('helpers.placement_max'))
                ->integer()
                ->minValue(1)
                ->maxValue(AchievementRule::MAX_PLACEMENT)
                ->visible($activity),

            Select::make(self::BASIS)
                ->label(self::text('fields.basis'))
                ->helperText(self::text('helpers.basis'))
                ->options([
                    YearsBasis::Account->value => self::text('basis.account'),
                    YearsBasis::Membership->value => self::text('basis.membership'),
                ])
                ->native(false)
                ->required(static fn (Get $get): bool => $get(self::METRIC) === MetricType::Years->value)
                ->visible(static fn (Get $get): bool => $get(self::METRIC) === MetricType::Years->value),

            Select::make(self::WINDOW)
                ->label(self::text('fields.window'))
                ->helperText(static fn (Get $get, ?Model $record): string => self::text(match (true) {
                    self::windowLocked($record) => 'helpers.window_locked',
                    $get(self::WINDOW) === WindowType::Streak->value => 'helpers.window_streak',
                    default => 'helpers.window',
                }))
                ->options(static fn (Get $get): array => self::windowOptions(self::string($get(self::METRIC))))
                ->default(WindowType::AllTime->value)
                ->selectablePlaceholder(false)
                ->native(false)
                // Locked once somebody holds it, and still sent, so the save keeps the stored one
                ->disabled(static fn (?Model $record): bool => self::windowLocked($record))
                ->dehydrated()
                ->visible(static fn (Get $get, ?Model $record): bool => ! in_array($get(self::METRIC), [MetricType::Years->value, MetricType::People->value, MetricType::Recognitions->value], true) && self::offersWindows($record))
                ->live(),

            TextInput::make(self::STREAK_MONTHS)
                ->label(self::text('fields.streak_months'))
                ->helperText(self::text('helpers.streak_months'))
                ->integer()
                ->minValue(RuleWindow::MIN_STREAK_MONTHS)
                ->maxValue(static fn (Get $get): int => $get(self::STREAK_PER_SEASON) === true ? RuleWindow::MAX_SEASON_STREAK_MONTHS : RuleWindow::MAX_STREAK_MONTHS)
                ->default(3)
                ->required($streak)
                ->visible($streak),

            Toggle::make(self::STREAK_PER_SEASON)
                ->label(self::text('fields.streak_per_season'))
                ->helperText(self::text('helpers.streak_per_season'))
                ->disabled(static fn (?Model $record): bool => self::windowLocked($record))
                ->dehydrated()
                ->visible($streak)
                ->live(),

            TextInput::make(self::THRESHOLD)
                ->label(static fn (Get $get): string => self::text($streak($get) ? 'fields.threshold_per_month' : 'fields.threshold'))
                ->helperText(static fn (Get $get): string => self::text($streak($get) ? 'helpers.threshold_per_month' : 'helpers.threshold'))
                ->integer()
                ->minValue(1)
                ->maxValue(AchievementRule::MAX_THRESHOLD)
                ->default(1)
                ->required(static fn (Get $get): bool => $get(self::METRIC) !== MetricType::Manual->value)
                ->visible(static fn (Get $get): bool => $get(self::METRIC) !== MetricType::Manual->value),
        ];
    }

    /**
     * The form fields of a stored rule; an unreadable one starts the builder empty.
     *
     * @return array<string, mixed>
     */
    public static function fill(mixed $storedRule): array
    {
        $rule = AchievementRule::tryFrom($storedRule);

        if (! $rule instanceof AchievementRule) {
            return array_fill_keys(self::FIELDS, null);
        }

        return [
            self::METRIC => $rule->metric->value,
            self::KIND => $rule->kind,
            self::ROLE => $rule->role,
            self::WITH_ROLE => $rule->withRole,
            self::ATTRIBUTE_KEY => $rule->attributeKey,
            self::ATTRIBUTE_VALUE => $rule->attributeValue,
            self::PLACEMENT_MAX => $rule->placementMax,
            self::DISTINCT_KEY => $rule->distinctKey,
            self::BASIS => $rule->basis?->value,
            self::THRESHOLD => $rule->isManual() ? null : $rule->threshold,
            self::WINDOW => $rule->window->type->value,
            self::STREAK_MONTHS => $rule->window->months,
            self::STREAK_PER_SEASON => $rule->window->perSeason,
            self::RECOGNITION_TYPE => $rule->recognitionType,
        ];
    }

    /**
     * Replaces the `rule_*` fields of the form data with the stored `rule`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException when the fields make no rule AchievementRule can read
     */
    public static function intoRule(array $data, ?Model $record = null): array
    {
        $stored = self::stored($record);
        $rule = self::rule($data, $stored);

        if (! $rule instanceof AchievementRule) {
            throw ValidationException::withMessages(['data.'.self::METRIC => self::text('validation.invalid_rule')]);
        }

        // Checked here too: a disabled field can still be sent changed
        if ($stored instanceof AchievementRule && self::windowLocked($record) && ! self::sameKindOfWindow($stored, $rule)) {
            throw ValidationException::withMessages(['data.'.self::WINDOW => self::text('validation.window_locked')]);
        }

        return array_diff_key($data, array_flip(self::FIELDS)) + ['rule' => $rule->toArray()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  AchievementRule|null  $stored  the rule being edited: a value the sources no longer offer keeps its saved label
     */
    public static function rule(array $data, ?AchievementRule $stored = null): ?AchievementRule
    {
        $metric = MetricType::tryFrom((string) self::string($data[self::METRIC] ?? null));
        $threshold = self::integer($data[self::THRESHOLD] ?? null);
        $window = self::window($data);

        if (! $window instanceof RuleWindow) {
            return null;
        }

        if ($metric === MetricType::Manual) {
            return $window->isStreak() ? null : AchievementRule::manual(perSeason: $window->isSeasonal());
        }

        if (! $metric instanceof MetricType || $threshold === null) {
            return null;
        }

        if ($metric === MetricType::Years) {
            $basis = YearsBasis::tryFrom((string) self::string($data[self::BASIS] ?? null));

            return $basis instanceof YearsBasis ? AchievementRule::years($basis, $threshold) : null;
        }

        if ($metric === MetricType::Recognitions) {
            return $window->isAllTime() ? AchievementRule::recognitions($threshold, self::string($data[self::RECOGNITION_TYPE] ?? null)) : null;
        }

        $kind = self::string($data[self::KIND] ?? null);

        if ($kind === null) {
            return null;
        }

        if ($metric === MetricType::People) {
            return AchievementRule::people($kind, $threshold, self::string($data[self::ROLE] ?? null), self::string($data[self::WITH_ROLE] ?? null));
        }

        $attributeKey = self::string($data[self::ATTRIBUTE_KEY] ?? null);
        $attributeValue = $attributeKey === null ? null : self::string($data[self::ATTRIBUTE_VALUE] ?? null);
        $attributeLabel = $attributeKey === null || $attributeValue === null
            ? null
            : (self::metrics()->attributeValues($kind, $attributeKey)[$attributeValue] ?? null);

        if ($attributeLabel === null && $stored instanceof AchievementRule && $stored->attributeKey === $attributeKey && $stored->attributeValue === $attributeValue) {
            $attributeLabel = $stored->attributeLabel;
        }
        $role = self::string($data[self::ROLE] ?? null);
        $placement = self::integer($data[self::PLACEMENT_MAX] ?? null);

        if ($metric === MetricType::Distinct) {
            $key = self::string($data[self::DISTINCT_KEY] ?? null);

            return $key === null ? null : AchievementRule::distinct($kind, $key, $threshold, $role, $attributeKey, $attributeValue, $attributeLabel, $placement, $window);
        }

        return AchievementRule::count($kind, $threshold, $role, $attributeKey, $attributeValue, $attributeLabel, $placement, $window);
    }

    /**
     * The window of the form data; null for one that cannot be read (a streak without a valid
     * length). Without the field (hidden: years, or seasons off on a rule that had none), every
     * fact counts.
     *
     * @param  array<string, mixed>  $data
     */
    public static function window(array $data): ?RuleWindow
    {
        $type = WindowType::tryFrom((string) self::string($data[self::WINDOW] ?? null)) ?? WindowType::AllTime;

        return match ($type) {
            WindowType::AllTime => RuleWindow::allTime(),
            WindowType::Season => RuleWindow::season(),
            WindowType::Streak => ($months = self::integer($data[self::STREAK_MONTHS] ?? null)) === null
                ? null
                : RuleWindow::streak($months, ($data[self::STREAK_PER_SEASON] ?? false) === true),
        };
    }

    /**
     * The windows a metric can have: a streak only when counting activities.
     *
     * @return array<string, string>
     */
    public static function windowOptions(?string $metric): array
    {
        $options = [
            WindowType::AllTime->value => self::text('windows.all_time'),
            WindowType::Season->value => self::text('windows.season'),
        ];

        if ($metric === MetricType::Count->value) {
            $options[WindowType::Streak->value] = self::text('windows.streak');
        }

        return $options;
    }

    /**
     * Whether the kind of window can no longer change: somebody holds the achievement. Grants of one
     * that repeats keep their seasons and those of one that does not have none, so switching between
     * them would leave grants that mean something else; another achievement is made instead.
     */
    public static function windowLocked(?Model $record): bool
    {
        return $record instanceof AchievementModel && AchievementGrantModel::query()->where('achievement_id', $record->getKey())->exists();
    }

    /**
     * Same type (always, season or streak) and, for a streak, the same «within each season»; the
     * number of months may change, as a threshold may.
     */
    private static function sameKindOfWindow(AchievementRule $stored, AchievementRule $rule): bool
    {
        return $stored->window->type === $rule->window->type && $stored->window->perSeason === $rule->window->perSeason;
    }

    /**
     * Whether the window is offered: while seasons and streaks are on, and on a rule that already
     * has one whatever the switch.
     */
    private static function offersWindows(?Model $record): bool
    {
        $stored = self::stored($record);

        return ($stored instanceof AchievementRule && ! $stored->window->isAllTime())
            || app(AchievementsSettingsInterface::class)->seasonsAndStreaksEnabled();
    }

    /**
     * @return array<string, string>
     */
    public static function metricOptions(): array
    {
        $options = [];

        foreach (MetricType::cases() as $metric) {
            $options[$metric->value] = self::text('metrics.'.$metric->value);
        }

        return $options;
    }

    /**
     * The kinds with a source now, plus the one stored (which may have lost its source); for a
     * community rule, only the kinds done with other people.
     *
     * @return array<string, string>
     */
    public static function kindOptions(?string $current = null, bool $communityOnly = false): array
    {
        $offered = self::metrics()->kindsWithSource();
        $options = [];

        foreach (MetricKinds::ALL as $kind) {
            if ($communityOnly && ! in_array($kind, AchievementRule::COMMUNITY_KINDS, true)) {
                continue;
            }

            if (isset($offered[$kind])) {
                $options[$kind] = self::kindLabel($kind);
            } elseif ($kind === $current) {
                $options[$kind] = self::text('kind_without_source', ['kind' => self::kindLabel($kind)]);
            }
        }

        return $options;
    }

    public static function kindLabel(string $kind): string
    {
        $key = 'achievements::messages.settings.metric_kinds.'.$kind;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $kind;
    }

    /**
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        $options = [];

        foreach (ActivityRole::cases() as $role) {
            $options[$role->value] = self::text('roles.'.$role->value);
        }

        return $options;
    }

    /**
     * The only parts a `people` rule may name (AchievementRule::PEOPLE_ROLES).
     *
     * @return array<string, string>
     */
    public static function peopleRoleOptions(): array
    {
        return array_intersect_key(self::roleOptions(), array_flip(AchievementRule::PEOPLE_ROLES));
    }

    /**
     * @return array<string, string>
     */
    private static function attributeOptions(?string $kind, ?string $current): array
    {
        $options = $kind === null ? [] : self::metrics()->attributes($kind);

        if ($current !== null && ! isset($options[$current])) {
            $options[$current] = $current;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function valueOptions(?string $kind, ?string $key, ?string $current): array
    {
        $options = $kind === null || $key === null ? [] : self::metrics()->attributeValues($kind, $key);

        // A value the source no longer offers is kept, so saving the form never drops it unseen
        if ($current !== null && ! isset($options[$current])) {
            $options[$current] = self::text('value_not_offered', ['value' => $current]);
        }

        return $options;
    }

    /**
     * The rule stored on the record being edited: only its values are offered beyond what the
     * sources offer now, never one typed into the form.
     */
    public static function stored(?Model $record): ?AchievementRule
    {
        return $record instanceof Model ? AchievementRule::tryFrom($record->getRawOriginal('rule')) : null;
    }

    private static function metrics(): ActivityMetricsInterface
    {
        return app(ActivityMetricsInterface::class);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^\d{1,9}$/D', $value) === 1 ? (int) $value : null;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        return (string) __('achievements::messages.catalogue.rule.'.$key, $replace);
    }
}
