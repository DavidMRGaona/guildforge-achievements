<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use Modules\Achievements\Domain\ValueObjects\MetricKinds;

/**
 * Every switch of the settings page with its default.
 *
 * A mechanic that is off is hidden, its routes answer 404 and its rules are not evaluated; what
 * it already granted stays. Reading is tolerant: a value that is missing or of the wrong type
 * takes its default, so a release that adds a switch never needs a data change.
 */
final readonly class AchievementsSettings
{
    public const float DEFAULT_ANNOUNCEMENT_THRESHOLD = 10.0;

    public const int DEFAULT_RECOGNITION_WINDOW_DAYS = 14;

    public const float MIN_ANNOUNCEMENT_THRESHOLD = 0.1;

    public const float MAX_ANNOUNCEMENT_THRESHOLD = 100.0;

    public const int MIN_RECOGNITION_WINDOW_DAYS = 1;

    public const int MAX_RECOGNITION_WINDOW_DAYS = 365;

    /**
     * @param  array<string, bool>  $metricKinds  switch per kind (keys are MetricKinds values); a kind that is not listed counts as on
     */
    public function __construct(
        public bool $progress = true,
        public bool $rarity = true,
        public bool $secrets = true,
        public bool $seasonsAndStreaks = true,
        public bool $community = true,
        public bool $bulkGrants = true,
        public bool $announcements = true,
        public float $announcementsRarityThreshold = self::DEFAULT_ANNOUNCEMENT_THRESHOLD,
        public bool $points = false,
        public bool $levels = false,
        public bool $leaderboard = false,
        public bool $recognitions = false,
        public int $recognitionsWindowDays = self::DEFAULT_RECOGNITION_WINDOW_DAYS,
        public bool $challenges = false,
        public array $metricKinds = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $defaults = new self;

        return new self(
            progress: self::bool($raw, 'progress', $defaults->progress),
            rarity: self::bool($raw, 'rarity', $defaults->rarity),
            secrets: self::bool($raw, 'secrets', $defaults->secrets),
            seasonsAndStreaks: self::bool($raw, 'seasons_and_streaks', $defaults->seasonsAndStreaks),
            community: self::bool($raw, 'community', $defaults->community),
            bulkGrants: self::bool($raw, 'bulk_grants', $defaults->bulkGrants),
            announcements: self::bool($raw, 'announcements', $defaults->announcements),
            announcementsRarityThreshold: self::threshold($raw['announcements_rarity_threshold'] ?? null),
            points: self::bool($raw, 'points', $defaults->points),
            levels: self::bool($raw, 'levels', $defaults->levels),
            leaderboard: self::bool($raw, 'leaderboard', $defaults->leaderboard),
            recognitions: self::bool($raw, 'recognitions', $defaults->recognitions),
            recognitionsWindowDays: self::windowDays($raw['recognitions_window_days'] ?? null),
            challenges: self::bool($raw, 'challenges', $defaults->challenges),
            metricKinds: self::kinds($raw['metric_kinds'] ?? null),
        );
    }

    /**
     * The shape stored in the module's settings row (and read back by fromArray()).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'progress' => $this->progress,
            'rarity' => $this->rarity,
            'secrets' => $this->secrets,
            'seasons_and_streaks' => $this->seasonsAndStreaks,
            'community' => $this->community,
            'bulk_grants' => $this->bulkGrants,
            'announcements' => $this->announcements,
            'announcements_rarity_threshold' => $this->announcementsRarityThreshold,
            'points' => $this->points,
            'levels' => $this->levels,
            'leaderboard' => $this->leaderboard,
            'recognitions' => $this->recognitions,
            'recognitions_window_days' => $this->recognitionsWindowDays,
            'challenges' => $this->challenges,
            'metric_kinds' => $this->metricKinds,
        ];
    }

    /**
     * Whether a kind of activity counts. A kind with no stored switch is on, so a kind that a
     * later core adds works without anyone visiting the page.
     */
    public function metricKindEnabled(string $kind): bool
    {
        return $this->metricKinds[$kind] ?? true;
    }

    /**
     * Every known kind with its switch, in the order of MetricKinds::ALL, followed by any other
     * kind that was stored (a kind of a newer core or of a source that is gone is kept as it is).
     *
     * @return array<string, bool>
     */
    public function allMetricKinds(): array
    {
        $kinds = [];

        foreach (MetricKinds::ALL as $kind) {
            $kinds[$kind] = $this->metricKindEnabled($kind);
        }

        return $kinds + $this->metricKinds;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function bool(array $raw, string $key, bool $default): bool
    {
        $value = $raw[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    private static function threshold(mixed $value): float
    {
        if (! is_int($value) && ! is_float($value)) {
            return self::DEFAULT_ANNOUNCEMENT_THRESHOLD;
        }

        $threshold = (float) $value;

        if ($threshold < self::MIN_ANNOUNCEMENT_THRESHOLD || $threshold > self::MAX_ANNOUNCEMENT_THRESHOLD) {
            return self::DEFAULT_ANNOUNCEMENT_THRESHOLD;
        }

        return $threshold;
    }

    private static function windowDays(mixed $value): int
    {
        if (! is_int($value) || $value < self::MIN_RECOGNITION_WINDOW_DAYS || $value > self::MAX_RECOGNITION_WINDOW_DAYS) {
            return self::DEFAULT_RECOGNITION_WINDOW_DAYS;
        }

        return $value;
    }

    /**
     * @return array<string, bool>
     */
    private static function kinds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $kinds = [];

        foreach ($value as $kind => $enabled) {
            if (is_string($kind) && $kind !== '' && is_bool($enabled)) {
                $kinds[$kind] = $enabled;
            }
        }

        return $kinds;
    }
}
