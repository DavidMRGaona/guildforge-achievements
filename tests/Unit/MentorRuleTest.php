<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Unit;

use DateTimeImmutable;
use Modules\Achievements\Domain\Rules\AnnouncementWindow;
use Modules\Achievements\Domain\Rules\MentorRule;
use Modules\Achievements\Domain\ValueObjects\SubjectHosts;
use PHPUnit\Framework\TestCase;

final class MentorRuleTest extends TestCase
{
    private const string ANA = 'ana';

    private const string LUIS = 'luis';

    private const string MARTA = 'marta';

    private const string PEPA = 'pepa';

    public function test_whoever_ran_the_first_game_alone_is_the_mentor(): void
    {
        $this->assertSame(self::ANA, MentorRule::mentor(self::LUIS, [new SubjectHosts([self::ANA], [])]));
    }

    public function test_a_first_game_nobody_ran_has_no_mentor(): void
    {
        $this->assertNull(MentorRule::mentor(self::LUIS, [new SubjectHosts([], [self::ANA])]));
        $this->assertNull(MentorRule::mentor(self::LUIS, []));
    }

    public function test_nobody_is_their_own_mentor(): void
    {
        $this->assertNull(MentorRule::mentor(self::LUIS, [new SubjectHosts([self::LUIS], [self::LUIS])]));
        // Listed among the directors too (a co-director learning to run): the other one is the mentor
        $this->assertSame(self::ANA, MentorRule::mentor(self::LUIS, [new SubjectHosts([self::LUIS, self::ANA], [])]));
    }

    public function test_among_several_directors_only_the_one_who_created_the_table_is_the_mentor(): void
    {
        $this->assertSame(self::MARTA, MentorRule::mentor(self::LUIS, [new SubjectHosts([self::ANA, self::MARTA], [self::MARTA])]));
        // Created by somebody who did not run it (the association's team, say): no fair pick
        $this->assertNull(MentorRule::mentor(self::LUIS, [new SubjectHosts([self::ANA, self::MARTA], [self::PEPA])]));
        $this->assertNull(MentorRule::mentor(self::LUIS, [new SubjectHosts([self::ANA, self::MARTA], [])]));
    }

    public function test_two_first_games_at_the_same_instant_have_no_mentor(): void
    {
        $this->assertNull(MentorRule::mentor(self::LUIS, [new SubjectHosts([self::ANA], []), new SubjectHosts([self::MARTA], [])]));
    }

    public function test_ids_are_compared_in_lower_case_and_counted_once(): void
    {
        $this->assertSame('ana', MentorRule::mentor('luis', [new SubjectHosts(['ANA', 'ana'], [])]));
        $this->assertNull(MentorRule::mentor('luis', [new SubjectHosts(['LUIS'], [])]));
    }

    public function test_a_first_game_is_decided_only_once_its_grace_is_over(): void
    {
        $played = new DateTimeImmutable('2026-03-01 18:00:00');
        $decided = $played->modify('+'.AnnouncementWindow::ACTIVITY_DAYS.' days');

        $this->assertEquals($decided, MentorRule::decidedAt($played));
        $this->assertFalse(MentorRule::isDecided($played, $played->modify('+13 days')));
        // More than the grace: exactly fourteen days later is still waiting
        $this->assertFalse(MentorRule::isDecided($played, $decided));
        $this->assertTrue(MentorRule::isDecided($played, $decided->modify('+1 second')));
    }
}
