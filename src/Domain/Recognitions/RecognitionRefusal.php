<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Recognitions;

/**
 * Why the repository stored nothing when giving a recognition.
 */
enum RecognitionRefusal: string
{
    /** The giver already recognised that person for that subject (a concurrent request included) */
    case AlreadyGiven = 'already_given';

    /** Either account is anonymized or no longer exists */
    case AccountGone = 'account_gone';
}
