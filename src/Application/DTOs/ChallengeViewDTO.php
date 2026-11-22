<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use Modules\Achievements\Domain\Challenges\ChallengeState;

/**
 * A challenge as one person may see it: totals only, never which subjects anybody took part in.
 * `contribution` is the viewer's own (null for a guest); `completers` counts whoever got the reward,
 * and `people` lists those of them whose profile the viewer may see and who show their
 * achievements, by public name only (at most ChallengeBoardInterface::MAX_LISTED; empty for blocks).
 * Days are `Y-m-d` of the display zone.
 */
final readonly class ChallengeViewDTO
{
    /**
     * @param  list<ChallengeCompleterDTO>  $people
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public ChallengeState $state,
        public int $progress,
        public int $target,
        public string $unit,
        public string $startsOn,
        public string $endsOn,
        public bool $membersOnly,
        public AchievementViewDTO $reward,
        public int $contributors,
        public int $completers,
        public array $people,
        public ?int $contribution,
    ) {}
}
