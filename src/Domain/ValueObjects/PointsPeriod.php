<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * What points are added up over (#10): all time, or one Season (#8, a calendar year in the display
 * zone). A season's points are those of the grants made for it (`season_key`, a repeatable
 * achievement, whenever it was granted) plus those of the grants without a season made during it;
 * `from` and `until` are that year's bounds as storage-zone instants, `[from, until)`.
 */
final readonly class PointsPeriod
{
    private function __construct(
        public ?Season $season,
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
    public static function season(Season $season, DateTimeImmutable $from, DateTimeImmutable $until): self
    {
        if ($from >= $until) {
            throw new InvalidArgumentException('Not a season.');
        }

        return new self($season, $from, $until);
    }

    public function isAllTime(): bool
    {
        return ! $this->season instanceof Season;
    }

    /**
     * The season as grants store it ('2027'), or null for all time.
     */
    public function seasonKey(): ?string
    {
        return $this->season?->key();
    }

    /**
     * A short name for cache keys: `all` or the season's key.
     */
    public function key(): string
    {
        return $this->season?->key() ?? 'all';
    }
}
