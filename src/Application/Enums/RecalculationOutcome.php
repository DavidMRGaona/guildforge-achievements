<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Enums;

/**
 * How the history walk of one source ended. Only `Complete` removes stored subjects the history no
 * longer lists; every other outcome keeps what was read and takes nothing away.
 */
enum RecalculationOutcome: string
{
    /** Every page was read, up to the last one */
    case Complete = 'complete';

    /** No module offers the type now */
    case Missing = 'missing';

    /** A page could not be read */
    case Failed = 'failed';

    /** The history pointed back to a page already read */
    case Cycle = 'cycle';
}
