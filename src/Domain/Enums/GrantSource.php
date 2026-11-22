<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Enums;

/**
 * Why someone holds an achievement: a rule met (this module's evaluator), a person granting it by
 * hand to one account or to several (#4), or a collective challenge completed (#12).
 */
enum GrantSource: string
{
    case Rule = 'rule';

    case Manual = 'manual';

    case Bulk = 'bulk';

    case Challenge = 'challenge';
}
