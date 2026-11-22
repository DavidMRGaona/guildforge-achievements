<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Rules;

/**
 * What a rule measures. Later issues add their own (community achievements, challenges): a rule
 * with a metric this release does not know is unreadable, so it is never evaluated.
 */
enum MetricType: string
{
    /** Subjects of a kind the person took part in, with optional filters */
    case Count = 'count';

    /** Different values of one attribute among those subjects («3 sistemas distintos») */
    case Distinct = 'distinct';

    /** Years of account or of membership */
    case Years = 'years';

    /** Never granted by the evaluator: only by hand */
    case Manual = 'manual';
}
