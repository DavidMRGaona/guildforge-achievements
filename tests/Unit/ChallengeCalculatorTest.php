<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Unit;

use DateTimeImmutable;
use Modules\Achievements\Domain\Challenges\ChallengeCalculator;
use Modules\Achievements\Domain\Challenges\ChallengeMetric;
use Modules\Achievements\Domain\Challenges\ChallengeRule;
use Modules\Achievements\Domain\Entities\AchievementFact;
use Modules\Achievements\Domain\ValueObjects\FactAttribute;
use PHPUnit\Framework\TestCase;

final class ChallengeCalculatorTest extends TestCase
{
    private const string ANA = 'a0000000-0000-7000-8000-000000000001';

    private const string LUIS = 'a0000000-0000-7000-8000-000000000002';

    private const string MARTA = 'a0000000-0000-7000-8000-000000000003';

    public function test_subjects_count_each_subject_once(): void
    {
        $progress = new ChallengeCalculator()->progress($this->rule(ChallengeMetric::Subjects, 2), [
            $this->fact('t1', self::ANA, '2026-12-01 18:00:00'),
            $this->fact('t1', self::LUIS, '2026-12-01 18:00:00'),
            $this->fact('t2', self::MARTA, '2026-12-03 18:00:00'),
        ]);

        $this->assertSame(2, $progress->total);
        $this->assertSame([self::ANA, self::LUIS, self::MARTA], $progress->contributors());
        $this->assertSame(1, $progress->contributionOf(self::ANA));
        $this->assertEquals(new DateTimeImmutable('2026-12-03 18:00:00'), $progress->completedAt);
    }

    public function test_participations_count_each_person_in_each_subject(): void
    {
        $progress = new ChallengeCalculator()->progress($this->rule(ChallengeMetric::Participations, 10), [
            $this->fact('t1', self::ANA, '2026-12-01 18:00:00'),
            $this->fact('t1', self::LUIS, '2026-12-01 18:00:00'),
            $this->fact('t2', self::ANA, '2026-12-03 18:00:00'),
        ]);

        $this->assertSame(3, $progress->total);
        $this->assertSame(2, $progress->contributionOf(self::ANA));
        $this->assertSame(1, $progress->contributionOf(self::LUIS));
        $this->assertSame(0, $progress->contributionOf(self::MARTA));
        $this->assertNull($progress->completedAt);
    }

    public function test_people_count_each_person_once(): void
    {
        $progress = new ChallengeCalculator()->progress($this->rule(ChallengeMetric::People, 2), [
            $this->fact('t1', self::ANA, '2026-12-01 18:00:00'),
            $this->fact('t2', self::ANA, '2026-12-02 18:00:00'),
            $this->fact('t3', self::LUIS, '2026-12-05 18:00:00'),
        ]);

        $this->assertSame(2, $progress->total);
        $this->assertEquals(new DateTimeImmutable('2026-12-05 18:00:00'), $progress->completedAt);
    }

    public function test_two_roles_in_one_subject_count_once(): void
    {
        $progress = new ChallengeCalculator()->progress($this->rule(ChallengeMetric::Participations, 10), [
            $this->fact('t1', self::ANA, '2026-12-01 18:00:00', role: 'player'),
            $this->fact('t1', self::ANA, '2026-12-01 18:00:00', role: 'director'),
        ]);

        $this->assertSame(1, $progress->total);
    }

    public function test_the_filters_leave_facts_out(): void
    {
        $rule = ChallengeRule::make(ChallengeMetric::Subjects, 'game_session', 10, role: 'player', attributeKey: 'game_system', attributeValue: 'dnd5e', placementMax: 3);
        $this->assertInstanceOf(ChallengeRule::class, $rule);

        $progress = new ChallengeCalculator()->progress($rule, [
            $this->fact('t1', self::ANA, '2026-12-01 18:00:00', system: 'dnd5e', placement: 1),
            // Wrong role, system, placement, kind
            $this->fact('t2', self::ANA, '2026-12-01 18:00:00', role: 'director', system: 'dnd5e', placement: 1),
            $this->fact('t3', self::ANA, '2026-12-01 18:00:00', system: 'pathfinder', placement: 1),
            $this->fact('t4', self::ANA, '2026-12-01 18:00:00', system: 'dnd5e', placement: 4),
            $this->fact('t5', self::ANA, '2026-12-01 18:00:00', system: 'dnd5e'),
            $this->fact('t6', self::ANA, '2026-12-01 18:00:00', system: 'dnd5e', placement: 1, kind: 'tournament'),
        ]);

        $this->assertSame(1, $progress->total);
    }

    public function test_only_members_count_when_a_list_of_them_is_given(): void
    {
        $progress = new ChallengeCalculator()->progress($this->rule(ChallengeMetric::Participations, 10), [
            $this->fact('t1', self::ANA, '2026-12-01 18:00:00'),
            $this->fact('t1', self::LUIS, '2026-12-01 18:00:00'),
        ], members: [self::ANA => true]);

        $this->assertSame(1, $progress->total);
        $this->assertSame([self::ANA], $progress->contributors());
    }

    public function test_it_completes_with_the_fact_that_reached_the_target_whatever_the_order_given(): void
    {
        $progress = new ChallengeCalculator()->progress($this->rule(ChallengeMetric::Subjects, 2), [
            $this->fact('t3', self::MARTA, '2026-12-09 18:00:00'),
            $this->fact('t1', self::ANA, '2026-12-01 18:00:00'),
            $this->fact('t2', self::LUIS, '2026-12-04 18:00:00'),
        ]);

        $this->assertSame(3, $progress->total);
        $this->assertEquals(new DateTimeImmutable('2026-12-04 18:00:00'), $progress->completedAt);
        $this->assertEquals(new DateTimeImmutable('2026-12-09 18:00:00'), $progress->lastContributionOf(self::MARTA));
    }

    private function rule(ChallengeMetric $metric, int $target): ChallengeRule
    {
        $rule = ChallengeRule::make($metric, 'game_session', $target);
        $this->assertInstanceOf(ChallengeRule::class, $rule);

        return $rule;
    }

    private function fact(string $subject, string $userId, string $when, string $role = 'player', ?string $system = null, ?int $placement = null, string $kind = 'game_session'): AchievementFact
    {
        return new AchievementFact(
            sourceType: 'game-tables.session',
            subjectId: $subject,
            userId: $userId,
            role: $role,
            kind: $kind,
            occurredAt: new DateTimeImmutable($when),
            attributes: $system === null ? [] : ['game_system' => new FactAttribute($system, $system)],
            placement: $placement,
        );
    }
}
