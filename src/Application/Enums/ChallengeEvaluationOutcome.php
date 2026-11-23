<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Enums;

enum ChallengeEvaluationOutcome: string
{
    case Evaluated = 'evaluated';

    /** The «Retos» switch is off: nothing was counted */
    case Off = 'off';

    /** The settings could not be read: nothing was counted nor granted */
    case SettingsUnreadable = 'settings_unreadable';
}
