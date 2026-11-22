<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Achievements\Domain\Entities\AchievementFact;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\RuleEvaluator;
use Modules\Achievements\Domain\ValueObjects\FactAttribute;
use PHPUnit\Framework\TestCase;

final class RuleEvaluatorTest extends TestCase
{
    private const string ANA = '0195f3a2-7c1e-7d4b-9a8e-2f6c1b3d4e5f';

    private RuleEvaluator $evaluator;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new RuleEvaluator;
        $this->now = new DateTimeImmutable('2026-11-22 12:00:00', new DateTimeZone('UTC'));
    }

    public function test_count_counts_subjects_of_the_kind(): void
    {
        $facts = [
            $this->fact('s1', 'player'),
            $this->fact('s2', 'player'),
            $this->fact('t1', 'player', kind: 'tournament'),
        ];

        $this->assertSame(2, $this->value(['metric' => 'count', 'kind' => 'game_session', 'threshold' => 2], $facts));
        $this->assertTrue($this->met(['metric' => 'count', 'kind' => 'game_session', 'threshold' => 2], $facts));
        $this->assertFalse($this->met(['metric' => 'count', 'kind' => 'game_session', 'threshold' => 3], $facts));
    }

    public function test_one_subject_counts_once_whatever_roles_the_person_had(): void
    {
        $facts = [$this->fact('s1', 'player'), $this->fact('s1', 'creator'), $this->fact('s1', 'director')];

        $this->assertSame(1, $this->value(['metric' => 'count', 'kind' => 'game_session', 'threshold' => 1], $facts));
    }

    public function test_the_same_subject_id_of_two_sources_counts_twice(): void
    {
        $facts = [$this->fact('42', 'player', source: 'game-tables.session'), $this->fact('42', 'player', source: 'other.session')];

        $this->assertSame(2, $this->value(['metric' => 'count', 'kind' => 'game_session', 'threshold' => 1], $facts));
    }

    public function test_the_role_filter_keeps_only_that_role(): void
    {
        $facts = [$this->fact('s1', 'player'), $this->fact('s2', 'director'), $this->fact('s3', 'director')];

        $this->assertSame(2, $this->value(['metric' => 'count', 'kind' => 'game_session', 'filters' => ['role' => 'director'], 'threshold' => 1], $facts));
        $this->assertSame(1, $this->value(['metric' => 'count', 'kind' => 'game_session', 'filters' => ['role' => 'player'], 'threshold' => 1], $facts));
    }

    public function test_the_attribute_filter_keeps_only_that_value(): void
    {
        $facts = [$this->fact('s1', system: 'pf2'), $this->fact('s2', system: 'dnd5e'), $this->fact('s3'), $this->fact('s4', system: 'pf2')];

        $this->assertSame(2, $this->value(['metric' => 'count', 'kind' => 'game_session', 'filters' => ['attribute' => ['key' => 'game_system', 'value' => 'pf2']], 'threshold' => 1], $facts));
    }

    public function test_the_placement_filter_keeps_places_up_to_the_limit_and_never_facts_without_one(): void
    {
        $facts = [
            $this->fact('t1', kind: 'tournament', placement: 1),
            $this->fact('t2', kind: 'tournament', placement: 3),
            $this->fact('t3', kind: 'tournament', placement: 4),
            $this->fact('t4', kind: 'tournament'),
        ];

        $this->assertSame(2, $this->value(['metric' => 'count', 'kind' => 'tournament', 'filters' => ['placement_max' => 3], 'threshold' => 1], $facts));
        $this->assertSame(1, $this->value(['metric' => 'count', 'kind' => 'tournament', 'filters' => ['placement_max' => 1], 'threshold' => 1], $facts));
    }

    public function test_distinct_counts_different_values_of_the_attribute(): void
    {
        $facts = [
            $this->fact('s1', system: 'pf2'),
            $this->fact('s2', system: 'pf2'),
            $this->fact('s3', system: 'dnd5e'),
            $this->fact('s4', 'director', system: 'coc'),
            $this->fact('s5'),
        ];
        $rule = ['metric' => 'distinct', 'kind' => 'game_session', 'key' => 'game_system', 'threshold' => 3];

        $this->assertSame(3, $this->value($rule, $facts));
        $this->assertTrue($this->met($rule, $facts));
        $this->assertSame(2, $this->value(['filters' => ['role' => 'player']] + $rule, $facts));
    }

    public function test_account_years_are_full_years_since_the_first_account_fact_in_the_display_zone(): void
    {
        $madrid = new DateTimeZone('Europe/Madrid');
        // 2023-11-22 00:30 in Madrid
        $facts = [$this->fact('acc', 'owner', kind: 'account', when: '2023-11-21 23:30:00')];
        $rule = ['metric' => 'years', 'basis' => 'account', 'threshold' => 3];

        $justBefore = new DateTimeImmutable('2026-11-21 23:29:00', new DateTimeZone('UTC'));
        $justAfter = new DateTimeImmutable('2026-11-21 23:31:00', new DateTimeZone('UTC'));

        $this->assertSame(2, $this->value($rule, $facts, $justBefore, $madrid));
        $this->assertSame(3, $this->value($rule, $facts, $justAfter, $madrid));
        $this->assertTrue($this->met($rule, $facts, $justAfter, $madrid));
    }

    public function test_account_years_without_an_account_fact_are_none(): void
    {
        $this->assertSame(0, $this->value(['metric' => 'years', 'basis' => 'account', 'threshold' => 1], [$this->fact('s1')]));
    }

    public function test_an_account_created_in_the_future_has_no_years(): void
    {
        $facts = [$this->fact('acc', 'owner', kind: 'account', when: '2027-01-01 00:00:00')];

        $this->assertSame(0, $this->value(['metric' => 'years', 'basis' => 'account', 'threshold' => 1], $facts));
    }

    public function test_membership_years_count_the_membership_year_subjects(): void
    {
        $facts = [
            $this->fact('2024', 'member', kind: 'membership_year'),
            $this->fact('2025', 'member', kind: 'membership_year'),
            $this->fact('s1'),
        ];

        $this->assertSame(2, $this->value(['metric' => 'years', 'basis' => 'membership', 'threshold' => 2], $facts));
    }

    public function test_a_manual_rule_is_never_met(): void
    {
        $this->assertSame(0, $this->value(['metric' => 'manual'], [$this->fact('s1')]));
        $this->assertFalse($this->met(['metric' => 'manual'], [$this->fact('s1')]));
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  list<AchievementFact>  $facts
     */
    private function value(array $rule, array $facts, ?DateTimeImmutable $now = null, ?DateTimeZone $zone = null): int
    {
        return $this->evaluator->value($this->rule($rule), $facts, $now ?? $this->now, $zone ?? new DateTimeZone('UTC'));
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  list<AchievementFact>  $facts
     */
    private function met(array $rule, array $facts, ?DateTimeImmutable $now = null, ?DateTimeZone $zone = null): bool
    {
        return $this->evaluator->isMet($this->rule($rule), $facts, $now ?? $this->now, $zone ?? new DateTimeZone('UTC'));
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function rule(array $rule): AchievementRule
    {
        $read = AchievementRule::tryFrom(['version' => 1] + $rule);
        $this->assertInstanceOf(AchievementRule::class, $read);

        return $read;
    }

    private function fact(
        string $subject,
        string $role = 'player',
        string $kind = 'game_session',
        ?string $system = null,
        ?int $placement = null,
        string $when = '2026-03-14 18:00:00',
        string $source = 'game-tables.session',
    ): AchievementFact {
        return new AchievementFact(
            sourceType: $source,
            subjectId: $subject,
            userId: self::ANA,
            role: $role,
            kind: $kind,
            occurredAt: new DateTimeImmutable($when, new DateTimeZone('UTC')),
            attributes: $system === null ? [] : ['game_system' => new FactAttribute($system, ucfirst($system))],
            placement: $placement,
        );
    }
}
