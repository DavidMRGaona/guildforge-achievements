<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Enums;

/**
 * Whether the evaluator looks at an achievement now, and why not. Only Evaluated grants anything.
 */
enum AchievementStatus: string
{
    case Evaluated = 'evaluated';

    /** Switched off in the catalogue */
    case Inactive = 'inactive';

    /** Granted by hand only */
    case Manual = 'manual';

    /** The stored rule cannot be read */
    case InvalidRule = 'invalid_rule';

    /** No source of its kind is offered now («Sin fuente») */
    case NoSource = 'no_source';

    /** Its kind is switched off in the settings */
    case KindOff = 'kind_off';

    /** A secret achievement while secret achievements are switched off */
    case SecretsOff = 'secrets_off';

    public function isEvaluated(): bool
    {
        return $this === self::Evaluated;
    }

    public function label(): string
    {
        return (string) __('achievements::messages.catalogue.status.'.$this->value);
    }

    /**
     * Filament colour of its badge.
     */
    public function colour(): string
    {
        return match ($this) {
            self::Evaluated => 'success',
            self::Manual => 'info',
            self::Inactive => 'gray',
            self::NoSource, self::KindOff, self::SecretsOff => 'warning',
            self::InvalidRule => 'danger',
        };
    }
}
