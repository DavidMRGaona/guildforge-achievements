<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Challenges;

/**
 * What a challenge adds up, between everybody, from the facts of its kind in its dates.
 */
enum ChallengeMetric: string
{
    /** Subjects (a table, an event…) with at least one fact that counts: each one once, however many took part */
    case Subjects = 'subjects';

    /** Each person in each subject: a table of five players adds five */
    case Participations = 'participations';

    /** Different people with at least one fact that counts */
    case People = 'people';
}
