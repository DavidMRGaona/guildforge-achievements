<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use Modules\Achievements\Domain\Challenges\ChallengeState;

/**
 * A challenge as one person may see it: totals only, never which subjects anybody took part in.
 * `counted` is false while it was never counted (its 0 means nothing yet). `contribution` is the
 * viewer's own (null for a guest, and for whoever is not a member in a challenge of members);
 * `people` lists some of whoever got the reward, those whose profile the viewer may see and who
 * show their achievements (and their membership, in a challenge of members), by public name only
 * (at most ChallengeBoardInterface::MAX_LISTED; empty for blocks). How many got the reward is not
 * told. Days are `Y-m-d` of the display zone.
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
        public bool $counted,
        public int $progress,
        public int $target,
        public string $unit,
        public string $startsOn,
        public string $endsOn,
        public bool $membersOnly,
        public AchievementViewDTO $reward,
        public int $contributors,
        public array $people,
        public ?int $contribution,
    ) {}
}
