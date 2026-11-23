<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Support;

use App\Application\Services\TimezoneServiceInterface;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Domain\Challenges\ChallengeAudience;
use Modules\Achievements\Domain\Challenges\ChallengeMetric;
use Modules\Achievements\Domain\Challenges\ChallengeRewardRule;
use Modules\Achievements\Domain\Challenges\ChallengeRule;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\ChallengeModel;

/**
 * The challenge form: what it counts as flat fields (`rule_*`) that the pages turn into the stored
 * rule with ChallengeRule, and back, plus the days, who takes part and the reward. Kinds, roles and
 * attributes are offered as in an achievement's rule (AchievementRuleForm), from the sources there
 * are now plus what the challenge being edited already has. The reward is an achievement switched
 * on, granted by hand only and not secret (the challenge names its reward to everybody), or the one
 * it already had.
 */
final class ChallengeForm
{
    public const string METRIC = 'rule_metric';

    public const string KIND = 'rule_kind';

    public const string ROLE = 'rule_role';

    public const string ATTRIBUTE_KEY = 'rule_attribute_key';

    public const string ATTRIBUTE_VALUE = 'rule_attribute_value';

    public const string PLACEMENT_MAX = 'rule_placement_max';

    public const string TARGET = 'rule_target';

    /** @var list<string> */
    public const array FIELDS = [self::METRIC, self::KIND, self::ROLE, self::ATTRIBUTE_KEY, self::ATTRIBUTE_VALUE, self::PLACEMENT_MAX, self::TARGET];

    /**
     * @return list<Section>
     */
    public static function sections(): array
    {
        // Once complete, what it counts, its days, who takes part and its reward stay as they were
        $locked = static fn (?Model $record): bool => self::locksWhatCounts($record);

        return [
            Section::make(self::text('sections.challenge'))
                ->description(self::text('sections.challenge_description'))
                ->schema([
                    TextInput::make('name')
                        ->label(self::text('fields.name'))
                        ->required()
                        ->maxLength(80),

                    Textarea::make('description')
                        ->label(self::text('fields.description'))
                        ->helperText(self::text('helpers.description'))
                        ->maxLength(300)
                        ->rows(2)
                        ->dehydrateStateUsing(static fn (mixed $state): string => is_string($state) ? trim($state) : ''),

                    DatePicker::make('starts_on')
                        ->disabled($locked)
                        ->label(self::text('fields.starts_on'))
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required(),

                    DatePicker::make('ends_on')
                        ->disabled($locked)
                        ->label(self::text('fields.ends_on'))
                        ->helperText(self::text('helpers.ends_on'))
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->afterOrEqual('starts_on')
                        ->required(),

                    Select::make('audience')
                        ->disabled($locked)
                        ->label(self::text('fields.audience'))
                        ->helperText(self::text('helpers.audience'))
                        ->options(self::audienceOptions())
                        ->in(array_keys(self::audienceOptions()))
                        ->default(ChallengeAudience::Everybody->value)
                        ->native(false)
                        ->required(),

                    Select::make('reward_achievement_id')
                        ->disabled($locked)
                        ->label(self::text('fields.reward'))
                        ->helperText(self::text('helpers.reward'))
                        ->options(static fn (?Model $record): array => self::rewardOptions($record))
                        ->in(static fn (?Model $record): array => array_keys(self::rewardOptions($record)))
                        ->searchable()
                        ->native(false)
                        ->required(),
                ])
                ->columns(2),

            Section::make(self::text('sections.count'))
                ->description(static fn (?Model $record): string => match (true) {
                    self::locksWhatCounts($record) => self::text('sections.count_locked'),
                    self::hasStarted($record) => self::text('sections.count_started'),
                    default => self::text('sections.count_description'),
                })
                ->schema([
                    Select::make(self::KIND)
                        ->disabled($locked)
                        ->label(self::text('fields.kind'))
                        ->helperText(self::text('helpers.kind'))
                        ->options(static fn (?Model $record): array => AchievementRuleForm::kindOptions(self::stored($record)?->kind))
                        ->native(false)
                        ->required()
                        ->live(),

                    Select::make(self::METRIC)
                        ->disabled($locked)
                        ->label(self::text('fields.metric'))
                        ->helperText(self::text('helpers.metric'))
                        ->options(self::metricOptions())
                        ->default(ChallengeMetric::Subjects->value)
                        ->native(false)
                        ->required(),

                    Select::make(self::ROLE)
                        ->disabled($locked)
                        ->label(self::text('fields.role'))
                        ->helperText(self::text('helpers.role'))
                        ->options(AchievementRuleForm::roleOptions())
                        ->placeholder(__('achievements::messages.catalogue.rule.placeholders.any_role'))
                        ->native(false),

                    TextInput::make(self::PLACEMENT_MAX)
                        ->disabled($locked)
                        ->label(self::text('fields.placement_max'))
                        ->helperText(self::text('helpers.placement_max'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(1000),

                    Select::make(self::ATTRIBUTE_KEY)
                        ->disabled($locked)
                        ->label(self::text('fields.attribute_key'))
                        ->helperText(self::text('helpers.attribute_key'))
                        ->options(static fn (Get $get, ?Model $record): array => self::attributeOptions(self::string($get(self::KIND)), self::stored($record)?->attributeKey))
                        ->placeholder(__('achievements::messages.catalogue.rule.placeholders.no_attribute'))
                        ->native(false)
                        ->live(),

                    Select::make(self::ATTRIBUTE_VALUE)
                        ->disabled($locked)
                        ->label(self::text('fields.attribute_value'))
                        ->options(static fn (Get $get, ?Model $record): array => self::valueOptions(self::string($get(self::KIND)), self::string($get(self::ATTRIBUTE_KEY)), self::stored($record)?->attributeValue))
                        ->searchable()
                        ->native(false)
                        ->required(static fn (Get $get): bool => self::string($get(self::ATTRIBUTE_KEY)) !== null)
                        ->visible(static fn (Get $get): bool => self::string($get(self::ATTRIBUTE_KEY)) !== null),

                    TextInput::make(self::TARGET)
                        ->disabled($locked)
                        ->label(self::text('fields.target'))
                        ->helperText(self::text('helpers.target'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(ChallengeRule::MAX_TARGET)
                        ->required(),
                ])
                ->columns(2),
        ];
    }

    /**
     * Whether what the challenge counts, its days, who takes part and its reward are fixed: once it
     * is complete, its rewards were granted for exactly that (ChallengePolicy::changeWhatCounts()).
     */
    public static function locksWhatCounts(?Model $record): bool
    {
        return $record instanceof ChallengeModel && $record->completed_at !== null;
    }

    /**
     * The fields only a challenge that is not complete may change.
     *
     * @return list<string>
     */
    public static function lockedFields(): array
    {
        return [...self::FIELDS, 'rule', 'starts_on', 'ends_on', 'audience', 'reward_achievement_id'];
    }

    private static function hasStarted(?Model $record): bool
    {
        return $record instanceof ChallengeModel
            && $record->starts_on->format('Y-m-d') <= app(TimezoneServiceInterface::class)->today()->format('Y-m-d');
    }

    /**
     * The form fields of a stored rule; an unreadable one starts them empty.
     *
     * @return array<string, mixed>
     */
    public static function fill(mixed $storedRule): array
    {
        $rule = ChallengeRule::tryFrom($storedRule);

        if (! $rule instanceof ChallengeRule) {
            return array_fill_keys(self::FIELDS, null);
        }

        return [
            self::METRIC => $rule->metric->value,
            self::KIND => $rule->kind,
            self::ROLE => $rule->role,
            self::ATTRIBUTE_KEY => $rule->attributeKey,
            self::ATTRIBUTE_VALUE => $rule->attributeValue,
            self::PLACEMENT_MAX => $rule->placementMax,
            self::TARGET => $rule->target,
        ];
    }

    /**
     * Replaces the `rule_*` fields of the form data with the stored `rule`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException when the fields make no rule ChallengeRule can read
     */
    public static function intoRule(array $data, ?Model $record = null): array
    {
        $rule = self::rule($data, self::stored($record));

        if (! $rule instanceof ChallengeRule) {
            throw ValidationException::withMessages(['data.'.self::TARGET => self::text('validation.invalid_rule')]);
        }

        return array_diff_key($data, array_flip(self::FIELDS)) + ['rule' => $rule->toArray()];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function rule(array $data, ?ChallengeRule $stored = null): ?ChallengeRule
    {
        $metric = ChallengeMetric::tryFrom((string) self::string($data[self::METRIC] ?? null));
        $kind = self::string($data[self::KIND] ?? null);
        $target = self::integer($data[self::TARGET] ?? null);

        if (! $metric instanceof ChallengeMetric || $kind === null || $target === null) {
            return null;
        }

        $attributeKey = self::string($data[self::ATTRIBUTE_KEY] ?? null);
        $attributeValue = $attributeKey === null ? null : self::string($data[self::ATTRIBUTE_VALUE] ?? null);
        $attributeLabel = $attributeKey === null || $attributeValue === null
            ? null
            : (self::metrics()->attributeValues($kind, $attributeKey)[$attributeValue] ?? null);

        // A value the sources no longer offer keeps the name it was saved with
        if ($attributeLabel === null && $stored instanceof ChallengeRule && $stored->attributeKey === $attributeKey && $stored->attributeValue === $attributeValue) {
            $attributeLabel = $stored->attributeLabel;
        }

        return ChallengeRule::make(
            $metric,
            $kind,
            $target,
            self::string($data[self::ROLE] ?? null),
            $attributeKey,
            $attributeValue,
            $attributeLabel,
            self::integer($data[self::PLACEMENT_MAX] ?? null),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function metricOptions(): array
    {
        $options = [];

        foreach (ChallengeMetric::cases() as $metric) {
            $options[$metric->value] = self::text('metrics.'.$metric->value);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function audienceOptions(): array
    {
        $options = [];

        foreach (ChallengeAudience::cases() as $audience) {
            $options[$audience->value] = self::text('audiences.'.$audience->value);
        }

        return $options;
    }

    /**
     * Achievements switched on, granted by hand only and not secret, plus the reward the challenge
     * being edited already has (whatever it is now).
     *
     * @return array<string, string>
     */
    public static function rewardOptions(?Model $record): array
    {
        $options = [];

        foreach (AchievementModel::query()->where('is_active', true)->where('is_secret', false)->orderBy('name')->orderBy('id')->get() as $achievement) {
            if (ChallengeRewardRule::allows($achievement->is_active, $achievement->is_secret, $achievement->getRawOriginal('rule'))) {
                $options[$achievement->id] = $achievement->name;
            }
        }

        if ($record instanceof ChallengeModel && ! isset($options[$record->reward_achievement_id])) {
            $current = AchievementModel::query()->find($record->reward_achievement_id);

            if ($current instanceof AchievementModel) {
                $options[$current->id] = $current->name;
            }
        }

        return $options;
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

        if ($current !== null && ! isset($options[$current])) {
            $options[$current] = __('achievements::messages.catalogue.rule.value_not_offered', ['value' => $current]);
        }

        return $options;
    }

    public static function stored(?Model $record): ?ChallengeRule
    {
        return $record instanceof ChallengeModel ? ChallengeRule::tryFrom($record->getRawOriginal('rule')) : null;
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
        return (string) __('achievements::messages.challenges.'.$key, $replace);
    }
}
