<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Recognitions;

use DateTimeImmutable;
use DateTimeZone;

/**
 * What anybody but the owner counts (#11): only recognitions given before the first minute of the
 * current month in the display zone. A total that rose the night after a game would say who played
 * it; one that moves once a month says nothing about which game. The «recognitions received»
 * achievement metric counts the same, so the achievement never tells it either.
 */
final class RecognitionCut
{
    public static function at(DateTimeImmutable $now, DateTimeZone $displayZone): DateTimeImmutable
    {
        $local = $now->setTimezone($displayZone);

        return $local->setDate((int) $local->format('Y'), (int) $local->format('n'), 1)->setTime(0, 0);
    }
}
