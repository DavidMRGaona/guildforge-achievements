<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

/**
 * One account's points by achievement (#10). Somebody else sees only those of achievements that
 * are not secret now and of secret ones revealed to them (they hold it too, or the owner featured
 * it): the other secrets are only counted, never added up, so a total minus what can be seen never
 * gives a secret's points away. Which ones are secret is decided when it is read, from the catalogue
 * as it is now, not from when the points were added up.
 */
final readonly class PointsBreakdown
{
    /**
     * @param  array<string, int>  $points  achievement id in lower case => points it gives over the period
     */
    public function __construct(
        public array $points = [],
    ) {}

    public function total(): int
    {
        return array_sum($this->points);
    }

    /**
     * The total somebody else sees.
     *
     * @param  array<string, true>  $secret  ids of the achievements that are secret now
     * @param  array<string, true>  $revealed  ids of the secret ones revealed to them
     */
    public function visibleWith(array $secret, array $revealed): int
    {
        $visible = 0;

        foreach ($this->points as $id => $points) {
            if (! isset($secret[$id]) || isset($revealed[$id])) {
                $visible += $points;
            }
        }

        return $visible;
    }
}
