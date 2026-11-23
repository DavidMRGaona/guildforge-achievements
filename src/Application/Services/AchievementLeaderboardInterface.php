<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Achievements\Application\DTOs\LeaderboardPageDTO;
use Modules\Achievements\Domain\ValueObjects\PointsPeriod;

/**
 * The leaderboard (#10), for one signed-in viewer: only accounts whose public profile the viewer
 * may see (the core's ProfileAccessRule), with a public name, not deactivated or anonymized, that
 * neither hid their achievements nor opted out. Ranks count only those rows, so a position never
 * tells about anybody the viewer may not see. Totals count the grants still held that were made
 * before the nightly photo (PointsCutoffInterface), read on every request; the viewer's own row as it
 * is now. Never throws: anything that fails, or no photo yet, shows nobody.
 */
interface AchievementLeaderboardInterface
{
    public const int PER_PAGE = 25;

    public function page(Authenticatable $viewer, PointsPeriod $period, int $page): LeaderboardPageDTO;
}
