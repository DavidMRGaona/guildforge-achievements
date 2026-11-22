<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Rules;

use App\Domain\Activity\ActivityIdentifierRule;
use App\Domain\Activity\ActivityKind;
use App\Domain\Activity\ActivityRole;
use JsonException;

/**
 * When an achievement is granted, as stored in its `rule` column (versioned JSON):
 *
 *     {"version": 1, "metric": "count", "kind": "game_session",
 *      "filters": {"role": "player", "attribute": {"key": "game_system", "value": "<id>", "label": "…"}, "placement_max": 3},
 *      "threshold": 10, "window": {"type": "all_time"}}
 *     {"version": 1, "metric": "distinct", "kind": "game_session", "key": "game_system", "filters": {…}, "threshold": 3}
 *     {"version": 1, "metric": "years", "basis": "account" | "membership", "threshold": 5}
 *     {"version": 1, "metric": "manual"}
 *     {"version": 1, "metric": "manual", "window": {"type": "season"}}
 *
 * The window (RuleWindow) says which facts count: all of them, those of one season, or a run of
 * months in a row (count only). A seasonal window (a season, or a streak per season) makes the
 * achievement repeatable, granted once per season; a manual rule may be seasonal too («La mejor
 * partida de la temporada», granted by hand each year). Years rules count every fact.
 *
 * Reading never throws, and anything it does not understand makes the whole rule unreadable
 * (tryFrom() answers null): another version, an unknown metric, kind, role, basis or window, a key
 * it does not expect, a value of the wrong type or out of range. An unreadable rule is never
 * evaluated, never «matches everything»: grants are permanent, so a rule half understood must grant
 * nothing. Later issues fit in this shape: community achievements and challenges as new metrics;
 * each needs a release that reads them, and until then a rule using them is unreadable for an
 * older one (as a season or streak window is for a release before them).
 *
 * `filters.attribute.label` is only the value's name when the rule was saved, for the panel; it
 * never takes part in the evaluation.
 */
final readonly class AchievementRule
{
    public const int VERSION = 1;

    public const int MAX_THRESHOLD = 1_000_000;

    public const int MAX_PLACEMENT = 1_000;

    public const int MAX_LABEL_LENGTH = 191;

    /** Every fact, whenever it happened */
    public const string WINDOW_ALL_TIME = 'all_time';

    private const array KEYS = [
        'count' => ['version', 'metric', 'kind', 'filters', 'threshold', 'window'],
        'distinct' => ['version', 'metric', 'kind', 'key', 'filters', 'threshold', 'window'],
        'years' => ['version', 'metric', 'basis', 'threshold', 'window'],
        'manual' => ['version', 'metric', 'window'],
    ];

    /** Which facts count: all of them unless the rule says otherwise */
    public RuleWindow $window;

    private function __construct(
        public MetricType $metric,
        public int $threshold = 1,
        public ?string $kind = null,
        public ?string $role = null,
        public ?string $attributeKey = null,
        public ?string $attributeValue = null,
        public ?string $attributeLabel = null,
        public ?int $placementMax = null,
        public ?string $distinctKey = null,
        public ?YearsBasis $basis = null,
        ?RuleWindow $window = null,
    ) {
        $this->window = $window ?? RuleWindow::allTime();
    }

    public static function count(string $kind, int $threshold, ?string $role = null, ?string $attributeKey = null, ?string $attributeValue = null, ?string $attributeLabel = null, ?int $placementMax = null, ?RuleWindow $window = null): ?self
    {
        return self::tryFrom(self::withoutNulls([
            'version' => self::VERSION,
            'metric' => MetricType::Count->value,
            'kind' => $kind,
            'filters' => self::filters($role, $attributeKey, $attributeValue, $attributeLabel, $placementMax),
            'threshold' => $threshold,
            'window' => $window?->toArray(),
        ]));
    }

    public static function distinct(string $kind, string $key, int $threshold, ?string $role = null, ?string $attributeKey = null, ?string $attributeValue = null, ?string $attributeLabel = null, ?int $placementMax = null, ?RuleWindow $window = null): ?self
    {
        return self::tryFrom(self::withoutNulls([
            'version' => self::VERSION,
            'metric' => MetricType::Distinct->value,
            'kind' => $kind,
            'key' => $key,
            'filters' => self::filters($role, $attributeKey, $attributeValue, $attributeLabel, $placementMax),
            'threshold' => $threshold,
            'window' => $window?->toArray(),
        ]));
    }

    public static function years(YearsBasis $basis, int $threshold): ?self
    {
        return self::tryFrom(['version' => self::VERSION, 'metric' => MetricType::Years->value, 'basis' => $basis->value, 'threshold' => $threshold]);
    }

    /**
     * Granted by hand only; once per season when `perSeason`.
     */
    public static function manual(bool $perSeason = false): self
    {
        return new self(MetricType::Manual, window: $perSeason ? RuleWindow::season() : null);
    }

    /**
     * The stored rule (decoded or as JSON text), or null when it cannot be read whole.
     */
    public static function tryFrom(mixed $raw): ?self
    {
        if (is_string($raw)) {
            try {
                $raw = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
        }

        if (! self::isMap($raw) || ($raw['version'] ?? null) !== self::VERSION || ! is_string($raw['metric'] ?? null)) {
            return null;
        }

        $metric = MetricType::tryFrom($raw['metric']);

        if (! $metric instanceof MetricType || array_diff(array_keys($raw), self::KEYS[$metric->value]) !== []) {
            return null;
        }

        $window = array_key_exists('window', $raw) ? RuleWindow::tryFrom($raw['window']) : RuleWindow::allTime();

        if (! $window instanceof RuleWindow || ! self::allows($metric, $window)) {
            return null;
        }

        if ($metric === MetricType::Manual) {
            return self::manual($window->isSeasonal());
        }

        $threshold = self::integer($raw['threshold'] ?? null, self::MAX_THRESHOLD);

        if ($threshold === null) {
            return null;
        }

        if ($metric === MetricType::Years) {
            $basis = is_string($raw['basis'] ?? null) ? YearsBasis::tryFrom($raw['basis']) : null;

            return $basis instanceof YearsBasis ? new self($metric, $threshold, basis: $basis) : null;
        }

        return self::readActivityRule($metric, $threshold, $raw, $window);
    }

    public function isManual(): bool
    {
        return $this->metric === MetricType::Manual;
    }

    /**
     * Granted once per season (a season window, or a streak counted inside each season).
     */
    public function isSeasonal(): bool
    {
        return $this->window->isSeasonal();
    }

    /**
     * What progress is measured against: the months of a streak (its threshold is what each month
     * needs), the threshold otherwise.
     */
    public function target(): int
    {
        return $this->window->isStreak() ? (int) $this->window->months : $this->threshold;
    }

    /**
     * The kind of activity whose facts decide the rule: the rule's own, the one of a years basis, or
     * null for a manual rule.
     */
    public function evaluatedKind(): ?string
    {
        return match ($this->metric) {
            MetricType::Count, MetricType::Distinct => $this->kind,
            MetricType::Years => $this->basis?->kind(),
            MetricType::Manual => null,
        };
    }

    /**
     * The stored shape, which tryFrom() reads back the same.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->metric) {
            MetricType::Manual => ['version' => self::VERSION, 'metric' => $this->metric->value] + ($this->window->isAllTime() ? [] : ['window' => $this->window->toArray()]),
            MetricType::Years => [
                'version' => self::VERSION,
                'metric' => $this->metric->value,
                'basis' => $this->basis?->value,
                'threshold' => $this->threshold,
            ],
            MetricType::Count, MetricType::Distinct => self::withoutNulls([
                'version' => self::VERSION,
                'metric' => $this->metric->value,
                'kind' => $this->kind,
                'key' => $this->distinctKey,
                'filters' => self::filters($this->role, $this->attributeKey, $this->attributeValue, $this->attributeLabel, $this->placementMax),
                'threshold' => $this->threshold,
                'window' => $this->window->toArray(),
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function readActivityRule(MetricType $metric, int $threshold, array $raw, RuleWindow $window): ?self
    {
        $kind = is_string($raw['kind'] ?? null) ? ActivityKind::tryFrom($raw['kind']) : null;

        if (! $kind instanceof ActivityKind) {
            return null;
        }

        $distinctKey = null;

        if ($metric === MetricType::Distinct) {
            $distinctKey = $raw['key'] ?? null;

            if (! is_string($distinctKey) || ! ActivityIdentifierRule::isAttributeKey($distinctKey)) {
                return null;
            }
        }

        $filters = $raw['filters'] ?? [];

        if (! is_array($filters) || ($filters !== [] && ! self::isMap($filters)) || array_diff(array_keys($filters), ['role', 'attribute', 'placement_max']) !== []) {
            return null;
        }

        $role = $filters['role'] ?? null;

        if ($role !== null && (! is_string($role) || ! ActivityRole::tryFrom($role) instanceof ActivityRole)) {
            return null;
        }

        $placement = $filters['placement_max'] ?? null;

        if ($placement !== null) {
            $placement = self::integer($placement, self::MAX_PLACEMENT);

            if ($placement === null) {
                return null;
            }
        }

        $attribute = $filters['attribute'] ?? null;
        $attributeKey = null;
        $attributeValue = null;
        $attributeLabel = null;

        if ($attribute !== null) {
            if (! self::isMap($attribute) || array_diff(array_keys($attribute), ['key', 'value', 'label']) !== []) {
                return null;
            }

            $attributeKey = $attribute['key'] ?? null;
            $attributeValue = $attribute['value'] ?? null;
            $attributeLabel = $attribute['label'] ?? null;

            if (! is_string($attributeKey) || ! ActivityIdentifierRule::isAttributeKey($attributeKey) || ! is_string($attributeValue)) {
                return null;
            }

            $attributeValue = ActivityIdentifierRule::stableId($attributeValue);

            if ($attributeValue === null || ($attributeLabel !== null && ! is_string($attributeLabel))) {
                return null;
            }

            $attributeLabel = $attributeLabel === null ? null : mb_substr(trim($attributeLabel), 0, self::MAX_LABEL_LENGTH);
            $attributeLabel = $attributeLabel === '' ? null : $attributeLabel;
        }

        return new self(
            metric: $metric,
            threshold: $threshold,
            kind: $kind->value,
            role: $role,
            attributeKey: $attributeKey,
            attributeValue: $attributeValue,
            attributeLabel: $attributeLabel,
            placementMax: $placement,
            distinctKey: $distinctKey,
            window: $window,
        );
    }

    /**
     * Which windows each metric reads: a streak counts subjects month by month, so only count has
     * one; years always look at every fact; a manual rule is either once or once per season.
     */
    private static function allows(MetricType $metric, RuleWindow $window): bool
    {
        return match ($metric) {
            MetricType::Count => true,
            MetricType::Distinct, MetricType::Manual => ! $window->isStreak(),
            MetricType::Years => $window->isAllTime(),
        };
    }

    private static function integer(mixed $value, int $max): ?int
    {
        return is_int($value) && $value >= 1 && $value <= $max ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function filters(?string $role, ?string $attributeKey, ?string $attributeValue, ?string $attributeLabel, ?int $placementMax): ?array
    {
        $filters = self::withoutNulls([
            'role' => $role,
            'attribute' => $attributeKey === null && $attributeValue === null
                ? null
                : self::withoutNulls(['key' => $attributeKey, 'value' => $attributeValue, 'label' => $attributeLabel]),
            'placement_max' => $placementMax,
        ]);

        return $filters === [] ? null : $filters;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    private static function isMap(mixed $value): bool
    {
        return is_array($value) && $value !== [] && array_all(array_keys($value), static fn (int|string $key): bool => is_string($key));
    }
}
