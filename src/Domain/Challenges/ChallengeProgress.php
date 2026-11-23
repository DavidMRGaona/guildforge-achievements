<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Challenges;

use DateTimeImmutable;

/**
 * Where a challenge stands: the total between everybody, who added to it and when the total reached
 * the target (null while it has not). A person's contribution is the subjects they took part in that
 * count, whatever the metric adds up.
 */
final readonly class ChallengeProgress
{
    /**
     * @param  array<string, int>  $contributions  account id (lower case) => subjects that count, sorted by id
     * @param  array<string, DateTimeImmutable>  $lastContributions  account id => their latest fact that counts
     */
    public function __construct(
        public int $total,
        public array $contributions,
        public array $lastContributions,
        public ?DateTimeImmutable $completedAt,
    ) {}

    /**
     * @return list<string>
     */
    public function contributors(): array
    {
        return array_map(strval(...), array_keys($this->contributions));
    }

    public function contributionOf(string $userId): int
    {
        return $this->contributions[strtolower($userId)] ?? 0;
    }

    public function lastContributionOf(string $userId): ?DateTimeImmutable
    {
        return $this->lastContributions[strtolower($userId)] ?? null;
    }
}
