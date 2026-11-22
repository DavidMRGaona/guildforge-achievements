<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * A season: one calendar year in the display zone («Temporada 2027»). Its key, the year as four
 * digits, is what a grant of a repeatable achievement keeps (`season` and `season_key`).
 *
 * Which season an instant falls in is decided on its calendar date in the display zone, never on
 * the stored instant: a game on 31 December at 23:30 in Madrid is in that season even though it is
 * stored as the 31st at 22:30 UTC, and one on 1 January at 00:30 is in the next.
 */
final readonly class Season
{
    public const int MIN_YEAR = 1970;

    public const int MAX_YEAR = 9999;

    private function __construct(
        public int $year,
    ) {}

    public static function ofYear(int $year): ?self
    {
        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR ? new self($year) : null;
    }

    /**
     * The season a stored key names, or null for anything that is not a year of four digits.
     */
    public static function fromKey(?string $key): ?self
    {
        if ($key === null || preg_match('/^\d{4}$/D', $key) !== 1) {
            return null;
        }

        return self::ofYear((int) $key);
    }

    /**
     * The season of an instant, by its calendar date in the display zone.
     */
    public static function containing(DateTimeInterface $instant, DateTimeZone $displayZone): self
    {
        $year = (int) DateTimeImmutable::createFromInterface($instant)->setTimezone($displayZone)->format('Y');

        return new self(max(self::MIN_YEAR, min(self::MAX_YEAR, $year)));
    }

    public function key(): string
    {
        return sprintf('%04d', $this->year);
    }

    public function previous(): ?self
    {
        return self::ofYear($this->year - 1);
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year;
    }

    public function contains(DateTimeInterface $instant, DateTimeZone $displayZone): bool
    {
        return self::containing($instant, $displayZone)->equals($this);
    }
}
