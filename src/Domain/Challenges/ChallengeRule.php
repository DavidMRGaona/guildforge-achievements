<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Challenges;

use JsonException;
use Modules\Achievements\Domain\Rules\AchievementRule;

/**
 * What a challenge counts, as stored in its `rule` column (versioned JSON):
 *
 *     {"version": 1, "metric": "subjects" | "participations" | "people", "kind": "game_session",
 *      "filters": {"role": "player", "attribute": {"key": "game_system", "value": "<id>", "label": "…"}, "placement_max": 3},
 *      "target": 40}
 *
 * The kind and the filters mean exactly what they mean in an achievement's rule, and are read by
 * AchievementRule itself, so both never drift apart. Reading never throws and anything it does not
 * understand makes the whole rule unreadable (null): an unreadable challenge is never evaluated, so
 * it never completes and never grants. The dates, who takes part and the reward live in their own
 * columns.
 */
final readonly class ChallengeRule
{
    public const int VERSION = 1;

    public const int MAX_TARGET = AchievementRule::MAX_THRESHOLD;

    private const array KEYS = ['version', 'metric', 'kind', 'filters', 'target'];

    private function __construct(
        public ChallengeMetric $metric,
        public int $target,
        public string $kind,
        public ?string $role,
        public ?string $attributeKey,
        public ?string $attributeValue,
        public ?string $attributeLabel,
        public ?int $placementMax,
    ) {}

    public static function make(
        ChallengeMetric $metric,
        string $kind,
        int $target,
        ?string $role = null,
        ?string $attributeKey = null,
        ?string $attributeValue = null,
        ?string $attributeLabel = null,
        ?int $placementMax = null,
    ): ?self {
        $filters = array_filter([
            'role' => $role,
            'attribute' => $attributeKey === null && $attributeValue === null
                ? null
                : array_filter(['key' => $attributeKey, 'value' => $attributeValue, 'label' => $attributeLabel], static fn (mixed $value): bool => $value !== null),
            'placement_max' => $placementMax,
        ], static fn (mixed $value): bool => $value !== null);

        return self::tryFrom(array_filter([
            'version' => self::VERSION,
            'metric' => $metric->value,
            'kind' => $kind,
            'filters' => $filters === [] ? null : $filters,
            'target' => $target,
        ], static fn (mixed $value): bool => $value !== null));
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

        if (! is_array($raw) || $raw === [] || array_is_list($raw) || array_diff(array_keys($raw), self::KEYS) !== []) {
            return null;
        }

        if (($raw['version'] ?? null) !== self::VERSION || ! is_string($raw['metric'] ?? null)) {
            return null;
        }

        $metric = ChallengeMetric::tryFrom($raw['metric']);
        $target = $raw['target'] ?? null;

        if (! $metric instanceof ChallengeMetric || ! is_int($target) || $target < 1 || $target > self::MAX_TARGET) {
            return null;
        }

        // The kind and the filters as an achievement's rule reads them
        $filter = AchievementRule::tryFrom(array_filter([
            'version' => AchievementRule::VERSION,
            'metric' => 'count',
            'kind' => $raw['kind'] ?? null,
            'filters' => $raw['filters'] ?? null,
            'threshold' => 1,
        ], static fn (mixed $value): bool => $value !== null));

        if (! $filter instanceof AchievementRule || $filter->kind === null) {
            return null;
        }

        return new self(
            metric: $metric,
            target: $target,
            kind: $filter->kind,
            role: $filter->role,
            attributeKey: $filter->attributeKey,
            attributeValue: $filter->attributeValue,
            attributeLabel: $filter->attributeLabel,
            placementMax: $filter->placementMax,
        );
    }

    /**
     * The stored shape, which tryFrom() reads back the same.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $attribute = $this->attributeKey === null
            ? null
            : array_filter(['key' => $this->attributeKey, 'value' => $this->attributeValue, 'label' => $this->attributeLabel], static fn (mixed $value): bool => $value !== null);
        $filters = array_filter(['role' => $this->role, 'attribute' => $attribute, 'placement_max' => $this->placementMax], static fn (mixed $value): bool => $value !== null);

        return array_filter([
            'version' => self::VERSION,
            'metric' => $this->metric->value,
            'kind' => $this->kind,
            'filters' => $filters === [] ? null : $filters,
            'target' => $this->target,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
