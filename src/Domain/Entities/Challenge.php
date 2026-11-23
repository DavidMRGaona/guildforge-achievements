<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Entities;

use DateTimeImmutable;
use Modules\Achievements\Domain\Challenges\ChallengeAudience;
use Modules\Achievements\Domain\Challenges\ChallengeRule;
use Modules\Achievements\Domain\Challenges\ChallengeState;

/**
 * A collective challenge. `startsOn` and `endsOn` are calendar days of the display zone (`Y-m-d`),
 * both included; `rule` is null when the stored one cannot be read (never evaluated). `progress`
 * and `contributors` are the last count (`progressAt`, null while never counted); `updatedAt`
 * changes only when the panel saves it, so a count can tell its rule changed meanwhile. Instants
 * are in the storage zone.
 */
final readonly class Challenge
{
    /**
     * Facts that happened within the days are often recorded later (a table marked completed a
     * few days after it was played): a challenge is still counted this many days after its last one.
     */
    public const int LATE_FACTS_DAYS = 30;

    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public ?ChallengeRule $rule,
        public ChallengeAudience $audience,
        public string $startsOn,
        public string $endsOn,
        public string $rewardAchievementId,
        public int $progress,
        public int $contributors,
        public ?DateTimeImmutable $completedAt,
        public ?DateTimeImmutable $closedAt,
        public ?DateTimeImmutable $progressAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}

    /**
     * @param  string  $today  the display day (`Y-m-d`)
     */
    public function state(string $today): ChallengeState
    {
        return match (true) {
            $this->completedAt instanceof DateTimeImmutable => ChallengeState::Completed,
            $this->closedAt instanceof DateTimeImmutable => ChallengeState::Closed,
            $today < $this->startsOn => ChallengeState::Upcoming,
            $today > $this->endsOn => ChallengeState::Ended,
            default => ChallengeState::Running,
        };
    }

    /**
     * Whether its total is worked out again: not closed, started, and not past the days late facts
     * may still take to come in.
     *
     * @param  string  $today  the display day (`Y-m-d`)
     */
    public function isCounted(string $today): bool
    {
        if ($this->closedAt instanceof DateTimeImmutable || $today < $this->startsOn) {
            return false;
        }

        $lastDay = new DateTimeImmutable($this->endsOn)->modify('+'.self::LATE_FACTS_DAYS.' days')->format('Y-m-d');

        return $today <= $lastDay;
    }

    /**
     * Whether its days are over: who takes part is frozen from then on (late facts still count).
     *
     * @param  string  $today  the display day (`Y-m-d`)
     */
    public function hasEnded(string $today): bool
    {
        return $today > $this->endsOn;
    }

    public function isComplete(): bool
    {
        return $this->completedAt instanceof DateTimeImmutable;
    }
}
