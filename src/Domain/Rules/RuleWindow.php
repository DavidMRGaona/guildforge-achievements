<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Rules;

/**
 * Which facts a rule looks at, as stored in its `window`:
 *
 *     {"type": "all_time"}                                  every fact, granted once
 *     {"type": "season"}                                    the facts of one season, granted once per season
 *     {"type": "streak", "months": 3}                       3 display months in a row, each with `threshold` subjects, granted once
 *     {"type": "streak", "months": 3, "per_season": true}   the same inside one season, granted once per season
 *
 * Seasons are calendar years and months calendar months, both in the display zone. A seasonal
 * window (season, or a streak per season) makes the achievement repeatable: each season it is met
 * gives a grant of its own, which keeps the season.
 */
final readonly class RuleWindow
{
    public const int MIN_STREAK_MONTHS = 2;

    public const int MAX_STREAK_MONTHS = 36;

    /** A streak inside one season cannot be longer than the season */
    public const int MAX_SEASON_STREAK_MONTHS = 12;

    private function __construct(
        public WindowType $type,
        public ?int $months = null,
        public bool $perSeason = false,
    ) {}

    public static function allTime(): self
    {
        return new self(WindowType::AllTime);
    }

    public static function season(): self
    {
        return new self(WindowType::Season);
    }

    public static function streak(int $months, bool $perSeason = false): ?self
    {
        $max = $perSeason ? self::MAX_SEASON_STREAK_MONTHS : self::MAX_STREAK_MONTHS;

        return $months >= self::MIN_STREAK_MONTHS && $months <= $max ? new self(WindowType::Streak, $months, $perSeason) : null;
    }

    /**
     * The stored window, or null when it cannot be read whole (an unknown type or key, a length
     * out of range, a `per_season` that is not a boolean).
     */
    public static function tryFrom(mixed $raw): ?self
    {
        if (! is_array($raw) || ! is_string($raw['type'] ?? null)) {
            return null;
        }

        $type = WindowType::tryFrom($raw['type']);

        if (! $type instanceof WindowType) {
            return null;
        }

        $keys = array_keys($raw);

        if ($type !== WindowType::Streak) {
            if ($keys !== ['type']) {
                return null;
            }

            return $type === WindowType::Season ? self::season() : self::allTime();
        }

        if (array_diff($keys, ['type', 'months', 'per_season']) !== [] || ! is_int($raw['months'] ?? null)) {
            return null;
        }

        $perSeason = $raw['per_season'] ?? false;

        return is_bool($perSeason) ? self::streak($raw['months'], $perSeason) : null;
    }

    public function isAllTime(): bool
    {
        return $this->type === WindowType::AllTime;
    }

    public function isStreak(): bool
    {
        return $this->type === WindowType::Streak;
    }

    /**
     * Granted once per season: the season window, and a streak counted inside each season.
     */
    public function isSeasonal(): bool
    {
        return $this->type === WindowType::Season || ($this->type === WindowType::Streak && $this->perSeason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->type) {
            WindowType::Streak => ['type' => $this->type->value, 'months' => $this->months] + ($this->perSeason ? ['per_season' => true] : []),
            WindowType::AllTime, WindowType::Season => ['type' => $this->type->value],
        };
    }
}
