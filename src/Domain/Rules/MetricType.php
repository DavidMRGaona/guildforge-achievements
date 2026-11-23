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
     * Recognitions received (#11), of any type or of one: counted only up to the start of the
     * month in the display zone, as everybody else sees them, so the achievement never tells
     * when one arrived
     */
    case Recognitions = 'recognitions';

    /** Never granted by the evaluator: only by hand */
    case Manual = 'manual';

    /**
     * Community achievements: measured over other people's facts, never over the person's alone,
     * and evaluated only while the «Logros de comunidad» switch is on.
     */
    public function isCommunity(): bool
    {
        return $this === self::People;
    }
}
