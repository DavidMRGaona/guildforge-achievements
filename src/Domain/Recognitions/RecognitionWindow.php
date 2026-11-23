<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Recognitions;

use DateTimeImmutable;
use DateTimeZone;

/**
 * When a subject can be recognised (#11): once it happened, until the end of the display day that
 * comes `days` days after the day it happened, in the display zone. Only table sessions and
 * campaigns: a tournament gathers people who never sat at one table, so its list would name them.
 */
final class RecognitionWindow
{
    /** The kinds whose participants can recognise each other */
    public const array KINDS = ['game_session', 'campaign'];

    public static function recognisesKind(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    public static function isOpen(DateTimeImmutable $occurredAt, DateTimeImmutable $now, DateTimeZone $displayZone, int $days): bool
    {
        return $occurredAt <= $now && $now < self::closesAt($occurredAt, $displayZone, $days);
    }

    /**
     * The first instant it can no longer be recognised: midnight in the display zone after the last day.
     */
    public static function closesAt(DateTimeImmutable $occurredAt, DateTimeZone $displayZone, int $days): DateTimeImmutable
    {
        return $occurredAt->setTimezone($displayZone)->setTime(0, 0)->modify('+'.($days + 1).' days');
    }

    /**
     * The earliest instant a subject still open now may have happened at.
     */
    public static function opensSince(DateTimeImmutable $now, DateTimeZone $displayZone, int $days): DateTimeImmutable
    {
        return $now->setTimezone($displayZone)->setTime(0, 0)->modify('-'.$days.' days');
    }
}
