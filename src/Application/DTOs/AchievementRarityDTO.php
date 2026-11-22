<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

/**
 * How rare each achievement is: the share (in percent, 0 to 100) of the people active in the last
 * twelve months who hold it. Active people are accounts with a fact in the ledger in that time,
 * neither deactivated nor anonymized, and only accounts that still exist (the facts of an account
 * deleted for good are left out).
 */
final readonly class AchievementRarityDTO
{
    /**
     * @param  array<string, float>  $shares  achievement id in lower case => percent held among active people
     */
    public function __construct(
        public int $activePeople,
        public array $shares,
        public ?string $computedAt = null,
    ) {}

    public static function unknown(): self
    {
        return new self(0, []);
    }

    /**
     * The share of an achievement, or null while nobody is active (a share of nobody says nothing).
     */
    public function shareOf(string $achievementId): ?float
    {
        if ($this->activePeople === 0) {
            return null;
        }

        return $this->shares[strtolower($achievementId)] ?? 0.0;
    }

    /**
     * @return array{activePeople: int, shares: array<string, float>, computedAt: string|null}
     */
    public function toArray(): array
    {
        return ['activePeople' => $this->activePeople, 'shares' => $this->shares, 'computedAt' => $this->computedAt];
    }

    public static function fromArray(mixed $raw): ?self
    {
        if (! is_array($raw) || ! is_int($raw['activePeople'] ?? null) || ! is_array($raw['shares'] ?? null)) {
            return null;
        }

        $shares = [];

        foreach ($raw['shares'] as $id => $share) {
            if (! is_string($id) || (! is_float($share) && ! is_int($share))) {
                return null;
            }

            $shares[$id] = (float) $share;
        }

        $computedAt = $raw['computedAt'] ?? null;

        return new self(max(0, $raw['activePeople']), $shares, is_string($computedAt) ? $computedAt : null);
    }
}
