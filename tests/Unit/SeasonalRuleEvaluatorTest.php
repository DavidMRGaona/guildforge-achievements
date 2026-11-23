<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Achievements\Domain\Entities\AchievementFact;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\RuleEvaluator;
use Modules\Achievements\Domain\ValueObjects\FactAttribute;
use Modules\Achievements\Domain\ValueObjects\Season;
use PHPUnit\Framework\TestCase;

/**
 * Seasons and streaks, with facts stored in UTC and shown in Madrid: every boundary is a calendar
 * date in the display zone, also across the changes of time.
 */
final class SeasonalRuleEvaluatorTest extends TestCase
{
    private const string ANA = '0195f3a2-7c1e-7d4b-9a8e-2f6c1b3d4e5f';

    private RuleEvaluator $evaluator;

    private DateTimeZone $madrid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new RuleEvaluator;
        $this->madrid = new DateTimeZone('Europe/Madrid');
    }

    public function test_a_season_is_the_calendar_year_in_the_display_zone(): void
    {
        // 23:30 UTC on 31 December is already 1 January in Madrid
        $this->assertSame('2027', Season::containing($this->utc('2026-12-31 23:30:00'), $this->madrid)->key());
        $this->assertSame('2026', Season::containing($this->utc('2026-12-31 22:59:59'), $this->madrid)->key());
        $this->assertSame('2026', Season::containing($this->utc('2026-12-31 23:30:00'), new DateTimeZone('UTC'))->key());
    }

    public function test_season_keys_are_years_of_four_digits(): void
    {
        $this->assertSame(2027, Season::fromKey('2027')?->year);
        $this->assertNull(Season::fromKey('27'));
        $this->assertNull(Season::fromKey('2027-1'));
        $this->assertNull(Season::fromKey(''));
        $this->assertNull(Season::fromKey(null));
        $this->assertSame('2026', Season::containing($this->utc('2027-06-01 12:00:00'), $this->madrid)->previous()?->key());
    }

    public function test_a_season_rule_counts_only_the_facts_of_that_season(): void
    {
        $rule = $this->rule(['metric' => 'count', 'kind' => 'game_session', 'threshold' => 2, 'window' => ['type' => 'season']]);
        $facts = [
            $this->fact('s1', '2026-03-01 18:00:00'),
            $this->fact('s2', '2026-12-31 22:30:00'), // 23:30 in Madrid: still 2026
            $this->fact('s3', '2026-12-31 23:30:00'), // 00:30 on 1 January in Madrid: 2027
        ];
        $now = $this->utc('2027-01-10 12:00:00');

        $this->assertTrue($this->evaluator->isMet($rule, $facts, $now, $this->madrid, season: Season::fromKey('2026')));
        $this->assertFalse($this->evaluator->isMet($rule, $facts, $now, $this->madrid, season: Season::fromKey('2027')));
        // Progress is the current season's
        $this->assertSame(1, $this->evaluator->value($rule, $facts, $now, $this->madrid));
        $this->assertFalse($this->evaluator->isMet($rule, $facts, $now, $this->madrid));
        $this->assertEquals($this->utc('2026-12-31 22:30:00'), $this->evaluator->metAt($rule, $facts, $now, $this->madrid, season: Season::fromKey('2026')));
    }

    public function test_the_seasons_of_a_rule_are_those_with_a_matching_fact_up_to_now(): void
    {
        $rule = $this->rule(['metric' => 'count', 'kind' => 'game_session', 'filters' => ['role' => 'player'], 'threshold' => 1, 'window' => ['type' => 'season']]);
        $facts = [
            $this->fact('s1', '2025-05-01 18:00:00'),
            $this->fact('s2', '2027-02-01 18:00:00'),
            $this->fact('s3', '2026-05-01 18:00:00', role: 'director'),
            $this->fact('s4', '2028-02-01 18:00:00'), // dated ahead: no season to come
        ];

        $seasons = $this->evaluator->seasons($rule, $facts, $this->utc('2027-06-01 12:00:00'), $this->madrid);

        $this->assertSame(['2025', '2027'], array_map(static fn (Season $season): string => $season->key(), $seasons));
        $this->assertSame([], $this->evaluator->seasons($this->rule(['metric' => 'count', 'kind' => 'game_session', 'threshold' => 1]), $facts, $this->utc('2027-06-01 12:00:00'), $this->madrid));
    }

    public function test_distinct_values_per_season(): void
    {
        $rule = $this->rule(['metric' => 'distinct', 'kind' => 'game_session', 'key' => 'game_system', 'threshold' => 2, 'window' => ['type' => 'season']]);
        $facts = [
            $this->fact('s1', '2025-05-01 18:00:00', system: 'pf2'),
            $this->fact('s2', '2026-05-01 18:00:00', system: 'pf2'),
            $this->fact('s3', '2026-06-01 18:00:00', system: 'pf2'),
            $this->fact('s4', '2026-07-01 18:00:00', system: 'dnd5e'),
        ];
        $now = $this->utc('2026-08-01 12:00:00');

        $this->assertFalse($this->evaluator->isMet($rule, $facts, $now, $this->madrid, season: Season::fromKey('2025')));
        $this->assertTrue($this->evaluator->isMet($rule, $facts, $now, $this->madrid, season: Season::fromKey('2026')));
        $this->assertSame(2, $this->evaluator->value($rule, $facts, $now, $this->madrid));
    }

    public function test_a_streak_needs_months_in_a_row(): void
    {
        $rule = $this->rule(['metric' => 'count', 'kind' => 'game_session', 'threshold' => 1, 'window' => ['type' => 'streak', 'months' => 3]]);
        $broken = [
            $this->fact('s1', '2026-01-10 18:00:00'),
            $this->fact('s2', '2026-02-10 18:00:00'),
            $this->fact('s3', '2026-04-10 18:00:00'),
            $this->fact('s4', '2026-05-10 18:00:00'),
        ];
        $now = $this->utc('2026-05-20 12:00:00');

        $this->assertFalse($this->evaluator->isMet($rule, $broken, $now, $this->madrid));
        $this->assertSame(2, $this->evaluator->value($rule, $broken, $now, $this->madrid));

        $whole = [...$broken, $this->fact('s5', '2026-03-28 18:00:00')];

        $this->assertTrue($this->evaluator->isMet($rule, $whole, $now, $this->madrid));
        // Met when March, the third month in a row, got its game
        $this->assertEquals($this->utc('2026-03-28 18:00:00'), $this->evaluator->metAt($rule, $whole, $now, $this->madrid));
    }

    public function test_each_month_of_a_streak_needs_the_threshold(): void
    {
        $rule = $this->rule(['metric' => 'count', 'kind' => 'game_session', 'threshold' => 2, 'window' => ['type' => 'streak', 'months' => 2]]);
        $facts = [
            $this->fact('s1', '2026-01-10 18:00:00'),
            $this->fact('s1', '2026-01-10 18:00:00', role: 'director'),
            $this->fact('s2', '2026-02-10 18:00:00'),
            $this->fact('s3', '2026-02-20 18:00:00'),
        ];
        $now = $this->utc('2026-02-25 12:00:00');

        // January had one subject (two roles in it count once)
        $this->assertFalse($this->evaluator->isMet($rule, $facts, $now, $this->madrid));

        $facts[] = $this->fact('s4', '2026-01-25 18:00:00');

        $this->assertEquals($this->utc('2026-02-20 18:00:00'), $this->evaluator->metAt($rule, $facts, $now, $this->madrid));
    }

    public function test_streak_months_follow_the_display_zone_across_a_change_of_time(): void
    {
        $rule = $this->rule(['metric' => 'count', 'kind' => 'game_session', 'threshold' => 1, 'window' => ['type' => 'streak', 'months' => 2]]);
        // 31 March at 22:30 UTC is 1 April at 00:30 in Madrid (summer time began on the 29th)
        $facts = [$this->fact('s1', '2026-03-31 22:30:00'), $this->fact('s2', '2026-05-02 10:00:00')];
        $now = $this->utc('2026-05-10 12:00:00');

        $this->assertTrue($this->evaluator->isMet($rule, $facts, $now, $this->madrid));
        // In UTC the first game is in March, and March to May is not a run
        $this->assertFalse($this->evaluator->isMet($rule, $facts, $now, new DateTimeZone('UTC')));
    }

    public function test_streak_months_follow_the_display_zone_when_summer_time_ends(): void
    {
        $rule = $this->rule(['metric' => 'count', 'kind' => 'game_session', 'threshold' => 1, 'window' => ['type' => 'streak', 'months' => 2]]);
        // 31 October at 23:30 UTC is 1 November at 00:30 in Madrid (winter time began on the 25th)
        $facts = [$this->fact('s1', '2026-10-31 23:30:00'), $this->fact('s2', '2026-12-05 10:00:00')];
        $now = $this->utc('2026-12-10 12:00:00');

        $this->assertTrue($this->evaluator->isMet($rule, $facts, $now, $this->madrid));
        // 31 October at 22:30 UTC is still 23:30 on the 31st in Madrid: October, then December
        $this->assertFalse($this->evaluator->isMet($rule, [$this->fact('s1', '2026-10-31 22:30:00'), $facts[1]], $now, $this->madrid));
    }

    public function test_the_current_run_waits_for_this_month_and_ends_once_a_month_is_missed(): void
    {
        $rule = $this->rule(['metric' => 'count', 'kind' => 'game_session', 'threshold' => 1, 'window' => ['type' => 'streak', 'months' => 6]]);
        $facts = [
            $this->fact('s1', '2026-02-10 18:00:00'),
            $this->fact('s2', '2026-03-10 18:00:00'),
            $this->fact('s3', '2026-04-10 18:00:00'),
        ];

        // April counted; May has nothing yet, but it is still May
        $this->assertSame(3, $this->evaluator->value($rule, $facts, $this->utc('2026-05-15 12:00:00'), $this->madrid));
        $this->assertSame(3, $this->evaluator->value($rule, $facts, $this->utc('2026-04-15 12:00:00'), $this->madrid));
        // June: May was missed
        $this->assertSame(0, $this->evaluator->value($rule, $facts, $this->utc('2026-06-01 12:00:00'), $this->madrid));
        // 31 May at 22:30 UTC is already June in Madrid
        $this->assertSame(0, $this->evaluator->value($rule, $facts, $this->utc('2026-05-31 22:30:00'), $this->madrid));
    }

    public function test_a_streak_per_season_never_crosses_into_the_next_season(): void
    {
        $rule = $this->rule(['metric' => 'count', 'kind' => 'game_session', 'threshold' => 1, 'window' => ['type' => 'streak', 'months' => 3, 'per_season' => true]]);
        $facts = [
            $this->fact('s1', '2026-11-10 18:00:00'),
            $this->fact('s2', '2026-12-10 18:00:00'),
            $this->fact('s3', '2027-01-10 18:00:00'),
        ];
        $now = $this->utc('2027-01-20 12:00:00');

        $this->assertFalse($this->evaluator->isMet($rule, $facts, $now, $this->madrid, season: Season::fromKey('2026')));
        $this->assertFalse($this->evaluator->isMet($rule, $facts, $now, $this->madrid, season: Season::fromKey('2027')));
        // The run going on starts again with the season
        $this->assertSame(1, $this->evaluator->value($rule, $facts, $now, $this->madrid));

        $once = $this->rule(['metric' => 'count', 'kind' => 'game_session', 'threshold' => 1, 'window' => ['type' => 'streak', 'months' => 3]]);

        $this->assertTrue($this->evaluator->isMet($once, $facts, $now, $this->madrid));
        $this->assertSame(3, $this->evaluator->value($once, $facts, $now, $this->madrid));
    }

    public function test_a_manual_rule_per_season_is_never_met_and_has_no_seasons(): void
    {
        $rule = AchievementRule::manual(perSeason: true);
        $facts = [$this->fact('s1', '2026-02-10 18:00:00')];
        $now = $this->utc('2026-05-15 12:00:00');

        $this->assertFalse($this->evaluator->isMet($rule, $facts, $now, $this->madrid));
        $this->assertSame([], $this->evaluator->seasons($rule, $facts, $now, $this->madrid));
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

    private function utc(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('UTC'));
    }

    private function fact(string $subject, string $when, string $role = 'player', ?string $system = null): AchievementFact
    {
        return new AchievementFact(
            sourceType: 'game-tables.session',
            subjectId: $subject,
            userId: self::ANA,
            role: $role,
            kind: 'game_session',
            occurredAt: $this->utc($when),
            attributes: $system === null ? [] : ['game_system' => new FactAttribute($system, strtoupper($system))],
        );
    }
}
