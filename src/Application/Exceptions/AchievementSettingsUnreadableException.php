<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Exceptions;

use RuntimeException;

/**
 * An evaluation gave up for good because the module settings could not be read: the job ends in
 * `failed_jobs`, where it can be retried once the settings are fixed. Nothing was granted.
 */
final class AchievementSettingsUnreadableException extends RuntimeException
{
    public static function afterRetries(): self
    {
        return new self('The achievements settings could not be read: nothing was evaluated.');
    }
}
