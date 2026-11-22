<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use Modules\Achievements\Application\Enums\LeaderboardViewerStatus;

/**
 * One page of the leaderboard as one viewer sees it. `total` counts only the rows they may see;
 * `available` is false when it could not be worked out (then it shows nobody).
 */
final readonly class LeaderboardPageDTO
{
    /**
     * @param  list<LeaderboardEntryDTO>  $entries
     */
    public function __construct(
        public array $entries,
        public int $total,
        public int $page,
        public int $lastPage,
        public ?LeaderboardEntryDTO $viewer,
        public LeaderboardViewerStatus $viewerStatus,
        public bool $available = true,
    ) {}

    public static function unavailable(LeaderboardViewerStatus $viewerStatus): self
    {
        return new self([], 0, 1, 1, null, $viewerStatus, false);
    }
}
