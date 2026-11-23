<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\ShownToOthersRule;
use PHPUnit\Framework\TestCase;

final class ShownToOthersRuleTest extends TestCase
{
    private DateTimeZone $madrid;

    private DateTimeImmutable $photo;

    protected function setUp(): void
    {
        $this->madrid = new DateTimeZone('Europe/Madrid');
        $this->photo = new DateTimeImmutable('2026-11-23 02:30:00', new DateTimeZone('UTC'));
    }

    public function test_the_month_starts_at_midnight_where_people_are(): void
    {
        $this->assertSame('2026-10-31T23:00:00+00:00', ShownToOthersRule::closedMonthsBefore($this->photo, $this->madrid)->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
    }

    public function test_any_grant_before_the_photo_shows_and_none_after(): void
    {
        $rule = AchievementRule::count('game_session', 1);

        $this->assertTrue(ShownToOthersRule::shows($rule, $this->photo->modify('-1 second'), $this->photo, $this->madrid));
        $this->assertFalse(ShownToOthersRule::shows($rule, $this->photo, $this->photo, $this->madrid));
        $this->assertTrue(ShownToOthersRule::shows(null, $this->photo->modify('-1 day'), $this->photo, $this->madrid));
    }

    public function test_a_mentor_shows_only_once_its_month_is_over(): void
    {
        $mentor = AchievementRule::mentor(1);

        $this->assertFalse(ShownToOthersRule::shows($mentor, new DateTimeImmutable('2026-11-20 02:00:00', new DateTimeZone('UTC')), $this->photo, $this->madrid));
        // 23:30 UTC on 31 October is already November in Madrid
        $this->assertFalse(ShownToOthersRule::shows($mentor, new DateTimeImmutable('2026-10-31 23:30:00', new DateTimeZone('UTC')), $this->photo, $this->madrid));
        $this->assertTrue(ShownToOthersRule::shows($mentor, new DateTimeImmutable('2026-10-31 22:30:00', new DateTimeZone('UTC')), $this->photo, $this->madrid));
    }
}
