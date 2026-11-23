<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Rules;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Which grants anybody but the owner sees (the list, the count, the featured badges, points,
 * levels, the leaderboard, rarity and the panel's count for those who may not see grants): those
 * made strictly before the nightly photo and, for the achievements shown by closed months
 * («Mentor», AchievementRule::showsByClosedMonths()), only those made before the month of the
 * photo began in the display zone (achievements#37). A mentor's grant is given a fixed time after
 * the newcomer's first game; held back until its month is over, every newcomer of that month looks
 * the same, so nobody can pair a grant with somebody's first game. The owner sees their own at once.
 */
final class ShownToOthersRule
{
    /**
     * 00:00 of the first day of the display month the photo was taken in.
     */
    public static function closedMonthsBefore(DateTimeImmutable $takenAt, DateTimeZone $displayZone): DateTimeImmutable
    {
        return $takenAt->setTimezone($displayZone)->modify('first day of this month')->setTime(0, 0);
    }

    /**
     * @param  AchievementRule|null  $rule  the achievement's rule (null when it cannot be read)
     */
    public static function shows(?AchievementRule $rule, DateTimeImmutable $grantedAt, DateTimeImmutable $takenAt, DateTimeZone $displayZone): bool
    {
        if ($grantedAt >= $takenAt) {
            return false;
        }

        return $rule?->showsByClosedMonths() !== true || $grantedAt < self::closedMonthsBefore($takenAt, $displayZone);
    }
}
