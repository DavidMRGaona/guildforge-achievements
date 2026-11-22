<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use Modules\Achievements\Application\Services\AchievementLeaderboardInterface;
use Modules\Achievements\Domain\Events\AchievementGranted;
use Modules\Achievements\Domain\Events\AchievementRevoked;

/**
 * A grant or a revoke changes somebody's points: the leaderboard adds them up again on its next
 * request (one cache write, never a query here).
 */
final readonly class ForgetLeaderboardStandings
{
    public function __construct(
        private AchievementLeaderboardInterface $leaderboard,
    ) {}

    public function handle(AchievementGranted|AchievementRevoked $event): void
    {
        $this->leaderboard->forget();
    }
}
