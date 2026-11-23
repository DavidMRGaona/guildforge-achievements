<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Rules;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * Which grants anybody but the owner sees (the list, the count, the featured badges, points,
 * levels, the leaderboard, rarity and the panel's count for those who may not see grants): those
 * made strictly before the nightly photo and, for the achievements shown by closed months
 * («Mentor», AchievementRule::showsByClosedMonths()), only those made before the month of the
 * photo began in the display zone (achievements#37). A mentor's grant is given a fixed time after
 * the newcomer's first game; held back until its month is over, every newcomer of that month looks
 * the same, so nobody can pair a grant with somebody's first game. The owner sees their own at once.
 *
 * Whether an achievement is shown by closed months is read from the stored rule's `metric` alone
 * (byClosedMonths()), exactly as ShownToOthersGrants reads it in SQL, so both agree even for a rule
 * that cannot be read whole.
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
     * Whether a stored rule (decoded or as JSON text) names a metric shown by closed months.
     */
    public static function byClosedMonths(mixed $storedRule): bool
    {
        if (is_string($storedRule)) {
            try {
                $storedRule = json_decode($storedRule, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return false;
            }
        }

        $metric = is_array($storedRule) && is_string($storedRule['metric'] ?? null) ? MetricType::tryFrom($storedRule['metric']) : null;

        return $metric?->showsByClosedMonths() === true;
    }

    /**
     * @param  bool  $byClosedMonths  the achievement is shown by closed months (byClosedMonths())
     */
    public static function shows(bool $byClosedMonths, DateTimeImmutable $grantedAt, DateTimeImmutable $takenAt, DateTimeZone $displayZone): bool
    {
        if ($grantedAt >= $takenAt) {
            return false;
        }

        return ! $byClosedMonths || $grantedAt < self::closedMonthsBefore($takenAt, $displayZone);
    }
}
