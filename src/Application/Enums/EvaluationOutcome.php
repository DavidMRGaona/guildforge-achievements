<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Enums;

enum EvaluationOutcome: string
{
    case Evaluated = 'evaluated';

    /** The settings could not be read: nothing was evaluated nor granted */
    case SettingsUnreadable = 'settings_unreadable';
}
