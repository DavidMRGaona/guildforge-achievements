<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Rules;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Whether a grant may be announced: only when its rule was met just now. What was met long ago
 * (a person evaluated late: a recalculation that has not run yet or gave up, a kind switched back
 * on) is granted silently, whatever triggered the evaluation, so nobody gets a pile of old notices.
 *
 * - Account years: the anniversary fell within the last ANNIVERSARY_DAYS display days.
 * - Everything else: the fact that reached the threshold happened within the last ACTIVITY_DAYS
 *   (a table is often marked completed a few days after it was played).
 *
 * A met instant later than now (a fact dated ahead) counts as recent.
 */
final class AnnouncementWindow
{
    public const int ANNIVERSARY_DAYS = 2;

    public const int ACTIVITY_DAYS = 14;

    public function isRecent(AchievementRule $rule, DateTimeImmutable $metAt, DateTimeImmutable $now, DateTimeZone $displayZone): bool
    {
        if ($rule->metric === MetricType::Years && $rule->basis === YearsBasis::Account) {
            $today = $now->setTimezone($displayZone)->setTime(0, 0);

            return $metAt->setTimezone($displayZone)->setTime(0, 0) >= $today->modify('-'.self::ANNIVERSARY_DAYS.' days');
        }

        return $metAt >= $now->modify('-'.self::ACTIVITY_DAYS.' days');
    }
}
