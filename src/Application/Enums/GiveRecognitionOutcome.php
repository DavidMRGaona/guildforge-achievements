<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Enums;

/**
 * What giving a recognition did. Every refusal is the same `Refused`, whatever the reason (the
 * person was not there, hides their achievements, the window closed…): the answer never tells who
 * took part in what.
 */
enum GiveRecognitionOutcome: string
{
    case Given = 'given';

    /** The giver already recognised that person for that subject */
    case AlreadyGiven = 'already_given';

    case Refused = 'refused';
}
