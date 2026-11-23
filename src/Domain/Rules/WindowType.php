<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Rules;

/**
 * The window of a rule (RuleWindow): every fact, one season, or a run of months in a row.
 */
enum WindowType: string
{
    case AllTime = 'all_time';

    /** One calendar year in the display zone */
    case Season = 'season';

    /** Calendar months in a row in the display zone, each with enough facts */
    case Streak = 'streak';
}
