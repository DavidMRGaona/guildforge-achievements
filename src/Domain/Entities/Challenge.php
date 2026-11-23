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
 * and `contributors` are the last count (`progressAt`, null while never counted); `countVersion`
 * goes up whenever what it counts, its days, who takes part or its reward change, so a count can
 * tell it is of an older version. `shown*` are what others see of it (asOthersSeeIt()): the last
 * count the nightly count published. Instants are in the storage zone.
 */
final readonly class Challenge
{
    /**
     * Facts that happened within the days are often recorded later (a table marked completed a
     * few days after it was played): a challenge is still counted this many days after its last one.
     */
    public const int LATE_FACTS_DAYS = 30;

    /**
     * Days a challenge lasts at least, first and last included: the total of a shorter one would
     * tell who was at one particular table or event.
     */
    public const int MIN_DAYS = 28;

    /**
     * Days from `$startsOn` to `$endsOn` (`Y-m-d`), both included; 0 when the end comes first.
     */
    public static function lengthInDays(string $startsOn, string $endsOn): int
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startsOn);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endsOn);

        if (! $start instanceof DateTimeImmutable || ! $end instanceof DateTimeImmutable || $end < $start) {
            return 0;
        }

        return (int) $start->diff($end)->days + 1;
    }

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
        public int $countVersion = 1,
        public int $shownProgress = 0,
        public int $shownContributors = 0,
        public ?DateTimeImmutable $shownCompletedAt = null,
        public ?DateTimeImmutable $shownAt = null,
    ) {}

    /**
     * The challenge as anybody but each person's own contribution sees it. In a challenge of
     * members, the total, the number of people and the completion are those of the last nightly
     * count (`shownAt`, null while there was none), never the count of the moment: a total that
     * moves an hour after a table ends tells who sat at it, and who is a member. Everybody else's
     * challenges are shown as counted.
     */
    public function asOthersSeeIt(): self
    {
        if ($this->audience !== ChallengeAudience::Members) {
            return $this;
        }

        return new self(
            id: $this->id,
            name: $this->name,
            description: $this->description,
            rule: $this->rule,
            audience: $this->audience,
            startsOn: $this->startsOn,
            endsOn: $this->endsOn,
            rewardAchievementId: $this->rewardAchievementId,
            progress: $this->shownProgress,
            contributors: $this->shownContributors,
            completedAt: $this->shownCompletedAt,
            closedAt: $this->closedAt,
            progressAt: $this->shownAt,
            countVersion: $this->countVersion,
            shownProgress: $this->shownProgress,
            shownContributors: $this->shownContributors,
            shownCompletedAt: $this->shownCompletedAt,
            shownAt: $this->shownAt,
        );
    }

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
