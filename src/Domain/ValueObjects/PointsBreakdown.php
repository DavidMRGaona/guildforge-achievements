<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

/**
 * One account's points split in two (#10): what its achievements that are not secret give, and what
 * each secret one it holds gives. Somebody else sees only the secrets that are revealed to them (they
 * hold it too, or the owner featured it): the others are only counted, never added up, so a total
 * minus what can be seen never gives a secret's points away.
 */
final readonly class PointsBreakdown
{
    /**
     * @param  array<string, int>  $secrets  achievement id in lower case => points it gives over the period
     */
    public function __construct(
        public int $open = 0,
        public array $secrets = [],
    ) {}

    public function total(): int
    {
        return $this->open + array_sum($this->secrets);
    }

    /**
     * The total somebody else sees.
     *
     * @param  array<string, true>  $revealed  ids of the secret achievements revealed to them
     */
    public function visibleWith(array $revealed): int
    {
        return $this->open + array_sum(array_intersect_key($this->secrets, $revealed));
    }
}
