<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\AnnouncementWindow;
use Modules\Achievements\Domain\Rules\YearsBasis;
use PHPUnit\Framework\TestCase;

final class AnnouncementWindowTest extends TestCase
{
    public function test_an_anniversary_is_announced_within_two_display_days(): void
    {
        $madrid = new DateTimeZone('Europe/Madrid');
        $rule = AchievementRule::years(YearsBasis::Account, 1);
        $this->assertInstanceOf(AchievementRule::class, $rule);
        $anniversary = new DateTimeImmutable('2026-11-20 00:00', $madrid);
        $window = new AnnouncementWindow;

        $this->assertTrue($window->isRecent($rule, $anniversary, new DateTimeImmutable('2026-11-22 23:00', $madrid), $madrid));
        $this->assertFalse($window->isRecent($rule, $anniversary, new DateTimeImmutable('2026-11-23 00:30', $madrid), $madrid));
    }

    public function test_activity_is_announced_within_fourteen_days_of_the_fact_that_reached_the_threshold(): void
    {
        $utc = new DateTimeZone('UTC');
        $rule = AchievementRule::count('game_session', 10);
        $this->assertInstanceOf(AchievementRule::class, $rule);
        $now = new DateTimeImmutable('2026-11-22 12:00', $utc);
        $window = new AnnouncementWindow;

        $this->assertTrue($window->isRecent($rule, $now->modify('-14 days'), $now, $utc));
        $this->assertFalse($window->isRecent($rule, $now->modify('-15 days'), $now, $utc));
        $this->assertTrue($window->isRecent($rule, $now->modify('+1 day'), $now, $utc));
    }
}
