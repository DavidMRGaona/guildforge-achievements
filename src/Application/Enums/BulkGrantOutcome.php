<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Enums;

/**
 * How a bulk grant (or its preview) ended. Anything but Ready, Granted and Partial grants nothing.
 */
enum BulkGrantOutcome: string
{
    /** A preview that could go ahead */
    case Ready = 'ready';

    case Granted = 'granted';

    /** «Concesión en lote» is off in the settings */
    case Disabled = 'disabled';

    /** The settings could not be read: a grant is permanent, so nothing is decided on defaults */
    case SettingsUnreadable = 'settings_unreadable';

    /** The achievement is gone or switched off */
    case AchievementUnavailable = 'achievement_unavailable';

    /** The source is missing, could not describe the subject, or does not show it to whoever grants */
    case SubjectNotVisible = 'subject_not_visible';

    /** More people than BulkGrantInterface::MAX_PEOPLE: nothing is granted */
    case TooMany = 'too_many';

    /** A batch failed after others were granted: `granted` says how many */
    case Partial = 'partial';

    /** The source could not read who took part: nothing is guessed */
    case SourceUnavailable = 'source_unavailable';

}
