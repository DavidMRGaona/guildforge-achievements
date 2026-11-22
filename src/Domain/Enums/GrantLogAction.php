<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Enums;

/**
 * What an entry of the grant history records. Stored as it is: values never change.
 */
enum GrantLogAction: string
{
    case Granted = 'granted';

    case Revoked = 'revoked';
}
