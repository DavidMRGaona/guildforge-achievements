<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Support;

use App\Domain\Activity\ActivityRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\MetricType;
use Modules\Achievements\Domain\Rules\YearsBasis;
use Modules\Achievements\Domain\ValueObjects\MetricKinds;

/**
 * The rule builder of the achievement form: flat fields (`rule_*`) that the pages turn into the
 * stored rule with AchievementRule, and back. Kinds come from the sources offered now (plus the
 * kind of the rule being edited, marked «sin fuente»); attributes and their values from the sources
 * of the kind chosen (OffersActivityAttributes), plus the edited rule's own. A combination
 * AchievementRule cannot read is refused on save.
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

    /** @var list<string> */
    public const array FIELDS = [
        self::METRIC, self::KIND, self::ROLE, self::WITH_ROLE, self::ATTRIBUTE_KEY, self::ATTRIBUTE_VALUE,
        self::PLACEMENT_MAX, self::DISTINCT_KEY, self::BASIS, self::THRESHOLD,
    ];

    /**
     * @return list<Select|TextInput>
     */
    public static function components(): array
    {
        $activity = static fn (Get $get): bool => in_array($get(self::METRIC), [MetricType::Count->value, MetricType::Distinct->value], true);
        $people = static fn (Get $get): bool => $get(self::METRIC) === MetricType::People->value;
        $withKind = static fn (Get $get): bool => $activity($get) || $people($get);

        return [
            Select::make(self::METRIC)
                ->label(self::text('fields.metric'))
                ->helperText(self::text('helpers.metric'))
                ->options(self::metricOptions())
                ->default(MetricType::Count->value)
                ->native(false)
                ->required()
                ->live(),

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

            TextInput::make(self::THRESHOLD)
                ->label(self::text('fields.threshold'))
                ->helperText(self::text('helpers.threshold'))
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
        $rule = self::rule($data, self::stored($record));

        if (! $rule instanceof AchievementRule) {
            throw ValidationException::withMessages(['data.'.self::METRIC => self::text('validation.invalid_rule')]);
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

        if ($metric === MetricType::Manual) {
            return AchievementRule::manual();
        }

        if (! $metric instanceof MetricType || $threshold === null) {
            return null;
        }

        if ($metric === MetricType::Years) {
            $basis = YearsBasis::tryFrom((string) self::string($data[self::BASIS] ?? null));

            return $basis instanceof YearsBasis ? AchievementRule::years($basis, $threshold) : null;
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

            return $key === null ? null : AchievementRule::distinct($kind, $key, $threshold, $role, $attributeKey, $attributeValue, $attributeLabel, $placement);
        }

        return AchievementRule::count($kind, $threshold, $role, $attributeKey, $attributeValue, $attributeLabel, $placement);
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
