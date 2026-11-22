<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * The levels points climb (#10), from the settings page: each with the points it starts at and a
 * name. The first starts at 0, so everybody has a level, and every later one needs more points than
 * the one before.
 *
 * Read tolerantly, like the rest of the settings: tryFrom() gives null on anything it does not
 * understand, and the settings fall back to the starter scale.
 */
final readonly class LevelScale
{
    public const int MIN_LEVELS = 2;

    public const int MAX_LEVELS = 20;

    public const int NAME_MAX_LENGTH = 40;

    public const int MAX_THRESHOLD = 1_000_000;

    /**
     * The starter scale, as thresholds and name keys (the names are translated where the settings
     * are read): what the starter catalogue's points (about 335 in all) climb.
     */
    public const array STARTER = [
        [0, 'first_steps'],
        [20, 'apprentice'],
        [50, 'regular'],
        [100, 'pillar'],
        [175, 'reference'],
        [275, 'legend'],
    ];

    /** @var list<Level> */
    public array $levels;

    /**
     * @param  list<array{threshold: int, name: string}>  $levels
     *
     * @throws InvalidArgumentException when the levels are not a valid scale
     */
    public function __construct(array $levels)
    {
        $count = count($levels);

        if ($count < self::MIN_LEVELS || $count > self::MAX_LEVELS) {
            throw new InvalidArgumentException('A level scale has between '.self::MIN_LEVELS.' and '.self::MAX_LEVELS.' levels.');
        }

        $built = [];
        $previous = -1;

        foreach ($levels as $index => $level) {
            $threshold = $level['threshold'];
            $name = self::cleanName($level['name']);

            if ($index === 0 && $threshold !== 0) {
                throw new InvalidArgumentException('The first level starts at 0 points.');
            }

            if ($threshold <= $previous || $threshold > self::MAX_THRESHOLD) {
                throw new InvalidArgumentException('Every level needs more points than the one before.');
            }

            if ($name === '') {
                throw new InvalidArgumentException('Every level has a name.');
            }

            $built[] = new Level($index + 1, $name, $threshold);
            $previous = $threshold;
        }

        $this->levels = $built;
    }

    /**
     * A stored scale (a JSON list of `{threshold, name}`), or null when it is not one.
     */
    public static function tryFrom(mixed $stored): ?self
    {
        if (! is_array($stored) || ! array_is_list($stored)) {
            return null;
        }

        $levels = [];

        foreach ($stored as $level) {
            if (! is_array($level) || array_diff(array_keys($level), ['threshold', 'name']) !== []) {
                return null;
            }

            $threshold = $level['threshold'] ?? null;
            $name = $level['name'] ?? null;

            if (! is_int($threshold) || ! is_string($name)) {
                return null;
            }

            $levels[] = ['threshold' => $threshold, 'name' => $name];
        }

        try {
            return new self($levels);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The level of a number of points: the last one whose threshold they reach.
     */
    public function levelFor(int $points): Level
    {
        $reached = $this->levels[0];

        foreach ($this->levels as $level) {
            if ($points < $level->threshold) {
                break;
            }

            $reached = $level;
        }

        return $reached;
    }

    /**
     * The level after the one of these points, or null at the top.
     */
    public function nextAfter(int $points): ?Level
    {
        return $this->levels[$this->levelFor($points)->number] ?? null;
    }

    /**
     * @return list<array{threshold: int, name: string}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (Level $level): array => ['threshold' => $level->threshold, 'name' => $level->name],
            $this->levels,
        );
    }

    /**
     * One line of plain text: no control or bidi characters, blanks collapsed, cut to the limit.
     */
    public static function cleanName(string $name): string
    {
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return mb_substr($name, 0, self::NAME_MAX_LENGTH);
    }
}
