<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Challenges;

/**
 * Whose facts add to a challenge.
 */
enum ChallengeAudience: string
{
    case Everybody = 'everybody';

    /** Only association members (the core decides who is one, when the challenge is counted) */
    case Members = 'members';
}
