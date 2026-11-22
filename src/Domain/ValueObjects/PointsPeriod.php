<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * What points are added up over (#10): all time, or one season.
 *
 * A season is a calendar year in the display zone (#8). Its points are those of the grants made for
 * that season (`season_key`, a repeatable achievement) plus those of the grants without a season
 * made during it; `from` and `until` are that year's bounds as storage-zone instants, `[from, until)`.
 */
final readonly class PointsPeriod
{
    public const int MIN_SEASON = 2000;

    public const int MAX_SEASON = 2999;

    private function __construct(
        public ?int $season,
        public ?DateTimeImmutable $from,
        public ?DateTimeImmutable $until,
    ) {}

    public static function allTime(): self
    {
        return new self(null, null, null);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function season(int $season, DateTimeImmutable $from, DateTimeImmutable $until): self
    {
        if ($season < self::MIN_SEASON || $season > self::MAX_SEASON || $from >= $until) {
            throw new InvalidArgumentException('Not a season.');
        }

        return new self($season, $from, $until);
    }

    public function isAllTime(): bool
    {
        return $this->season === null;
    }

    /**
     * The season as grants store it ('2027'), or null for all time.
     */
    public function seasonKey(): ?string
    {
        return $this->season === null ? null : (string) $this->season;
    }

    /**
     * A short name for cache keys: `all` or the year.
     */
    public function key(): string
    {
        return $this->season === null ? 'all' : (string) $this->season;
    }
}
