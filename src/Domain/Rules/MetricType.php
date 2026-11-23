<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Rules;

/**
 * What a rule measures. Later issues add their own (challenges, say): a rule with a metric this
 * release does not know is unreadable, so it is never evaluated.
 */
enum MetricType: string
{
    /** Subjects of a kind the person took part in, with optional filters */
    case Count = 'count';

    /** Different values of one attribute among those subjects («3 sistemas distintos») */
    case Distinct = 'distinct';

    /** Years of account or of membership */
    case Years = 'years';

    /** Different people met in subjects of a kind («jugar con 10 personas distintas») */
    case People = 'people';

    /**
     * Different people whose first game session as a player was one the person ran («Mentor»),
     * decided by the nightly batch alone (MentorRule)
     */
    case Mentor = 'mentor';

    /** Never granted by the evaluator: only by hand */
    case Manual = 'manual';

    /**
     * Community achievements: measured over other people's facts, never over the person's alone,
     * and evaluated only while the «Logros de comunidad» switch is on.
     */
    public function isCommunity(): bool
    {
        return $this === self::People || $this === self::Mentor;
    }

    /**
     * Evaluated only by the nightly batch, never when facts change nor by any other evaluation: a
     * mentor is decided once the newcomer's first game is old enough, and its grant must never fall
     * close to that game (MentorRule).
     */
    public function isNightly(): bool
    {
        return $this === self::Mentor;
    }

    /**
     * Others see its grants only once their month is over in the display zone (ShownToOthersRule):
     * a mentor's grant falls a fixed time after the newcomer's first game, so a grant seen within
     * its month could be paired with that game.
     */
    public function showsByClosedMonths(): bool
    {
        return $this === self::Mentor;
    }
}
