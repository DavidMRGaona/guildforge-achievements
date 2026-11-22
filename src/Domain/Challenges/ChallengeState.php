<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Challenges;

/**
 * Where a challenge is, for whoever looks at it.
 */
enum ChallengeState: string
{
    /** Its first day has not come yet */
    case Upcoming = 'upcoming';

    /** Within its days and not complete yet */
    case Running = 'running';

    /** The total reached the target (it may still be within its days: more people can add to it) */
    case Completed = 'completed';

    /** Its days are over and the target was not reached */
    case Ended = 'ended';

    /** Closed by hand before it was complete */
    case Closed = 'closed';
}
