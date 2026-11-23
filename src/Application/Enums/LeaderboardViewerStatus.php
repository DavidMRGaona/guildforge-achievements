<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Enums;

/**
 * Whether the person looking at the leaderboard is in it, and if not, why: only ever about
 * themselves, so they know what to change.
 */
enum LeaderboardViewerStatus: string
{
    case Listed = 'listed';
    case NoPoints = 'no_points';
    case Hidden = 'hidden';
    case OptedOut = 'opted_out';
    case NoPublicName = 'no_public_name';
}
