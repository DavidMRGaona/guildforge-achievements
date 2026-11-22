<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Unit;

use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\MetricType;
use Modules\Achievements\Domain\Rules\RuleWindow;
use Modules\Achievements\Domain\Rules\WindowType;
use Modules\Achievements\Domain\Rules\YearsBasis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AchievementRuleTest extends TestCase
{
    public function test_a_count_rule_is_read_with_every_filter(): void
    {
        $rule = AchievementRule::tryFrom([
            'version' => 1,
            'metric' => 'count',
            'kind' => 'game_session',
            'filters' => [
                'role' => 'player',
                'attribute' => ['key' => 'game_system', 'value' => 'pf2', 'label' => 'Pathfinder 2'],
                'placement_max' => 3,
            ],
            'threshold' => 10,
            'window' => ['type' => 'all_time'],
        ]);

        $this->assertInstanceOf(AchievementRule::class, $rule);
        $this->assertSame(MetricType::Count, $rule->metric);
        $this->assertSame('game_session', $rule->kind);
        $this->assertSame('game_session', $rule->evaluatedKind());
        $this->assertSame('player', $rule->role);
        $this->assertSame('game_system', $rule->attributeKey);
        $this->assertSame('pf2', $rule->attributeValue);
        $this->assertSame('Pathfinder 2', $rule->attributeLabel);
        $this->assertSame(3, $rule->placementMax);
        $this->assertSame(10, $rule->threshold);
        $this->assertFalse($rule->isManual());
    }

    public function test_optional_parts_may_be_missing_or_null(): void
    {
        $rule = AchievementRule::tryFrom([
            'version' => 1,
            'metric' => 'count',
            'kind' => 'event',
            'filters' => ['role' => null, 'attribute' => null, 'placement_max' => null],
            'threshold' => 5,
        ]);

        $this->assertInstanceOf(AchievementRule::class, $rule);
        $this->assertNull($rule->role);
        $this->assertNull($rule->attributeKey);
        $this->assertNull($rule->placementMax);

        $this->assertInstanceOf(AchievementRule::class, AchievementRule::tryFrom(['version' => 1, 'metric' => 'count', 'kind' => 'event', 'threshold' => 5]));
    }

    public function test_a_distinct_rule_needs_its_attribute_key(): void
    {
        $rule = AchievementRule::tryFrom(['version' => 1, 'metric' => 'distinct', 'kind' => 'game_session', 'key' => 'game_system', 'threshold' => 3]);

        $this->assertInstanceOf(AchievementRule::class, $rule);
        $this->assertSame(MetricType::Distinct, $rule->metric);
        $this->assertSame('game_system', $rule->distinctKey);

        $this->assertNull(AchievementRule::tryFrom(['version' => 1, 'metric' => 'distinct', 'kind' => 'game_session', 'threshold' => 3]));
        $this->assertNull(AchievementRule::tryFrom(['version' => 1, 'metric' => 'distinct', 'kind' => 'game_session', 'key' => 'Game System', 'threshold' => 3]));
    }

    public function test_a_years_rule_evaluates_the_kind_of_its_basis(): void
    {
        $account = AchievementRule::tryFrom(['version' => 1, 'metric' => 'years', 'basis' => 'account', 'threshold' => 3]);
        $membership = AchievementRule::tryFrom(['version' => 1, 'metric' => 'years', 'basis' => 'membership', 'threshold' => 1]);

        $this->assertInstanceOf(AchievementRule::class, $account);
        $this->assertSame(YearsBasis::Account, $account->basis);
        $this->assertSame('account', $account->evaluatedKind());
        $this->assertInstanceOf(AchievementRule::class, $membership);
        $this->assertSame('membership_year', $membership->evaluatedKind());
    }

    public function test_a_people_rule_counts_others_by_their_part(): void
    {
        $rule = AchievementRule::people('game_session', 20, 'director', 'player');

        $this->assertInstanceOf(AchievementRule::class, $rule);
        $this->assertSame(MetricType::People, $rule->metric);
        $this->assertSame('game_session', $rule->evaluatedKind());
        $this->assertSame('director', $rule->role);
        $this->assertSame('player', $rule->withRole);
        $this->assertTrue($rule->isCommunity());
        $this->assertSame(
            ['version' => 1, 'metric' => 'people', 'kind' => 'game_session', 'filters' => ['role' => 'director', 'with_role' => 'player'], 'threshold' => 20, 'window' => ['type' => 'all_time']],
            $rule->toArray(),
        );
        $this->assertNull(AchievementRule::people('venue_booking', 3));
    }

    public function test_rules_that_count_the_same_people_share_a_shape(): void
    {
        $ten = AchievementRule::people('game_session', 10, 'player', 'player');
        $fifty = AchievementRule::people('game_session', 50, 'player', 'player');
        $directed = AchievementRule::people('game_session', 10, 'director', 'player');

        $this->assertSame($ten?->peopleShape(), $fifty?->peopleShape());
        $this->assertNotSame($ten?->peopleShape(), $directed?->peopleShape());
    }

    public function test_a_manual_rule_has_nothing_to_evaluate(): void
    {
        $rule = AchievementRule::tryFrom(['version' => 1, 'metric' => 'manual']);

        $this->assertInstanceOf(AchievementRule::class, $rule);
        $this->assertTrue($rule->isManual());
        $this->assertNull($rule->evaluatedKind());
    }

    public function test_it_is_read_from_its_json_text_too(): void
    {
        $rule = AchievementRule::tryFrom('{"version":1,"metric":"count","kind":"forum_post","filters":{"role":"author"},"threshold":1}');

        $this->assertInstanceOf(AchievementRule::class, $rule);
        $this->assertSame('forum_post', $rule->kind);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    #[DataProvider('writtenRules')]
    public function test_what_it_writes_reads_back_the_same(array $rule): void
    {
        $read = AchievementRule::tryFrom($rule);

        $this->assertInstanceOf(AchievementRule::class, $read);
        $this->assertEquals($read, AchievementRule::tryFrom($read->toArray()));
        $this->assertSame(1, $read->toArray()['version']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function writtenRules(): iterable
    {
        yield 'count' => [['version' => 1, 'metric' => 'count', 'kind' => 'tournament', 'filters' => ['role' => 'player', 'placement_max' => 3], 'threshold' => 1]];
        yield 'count with attribute' => [['version' => 1, 'metric' => 'count', 'kind' => 'game_session', 'filters' => ['attribute' => ['key' => 'game_system', 'value' => 'pf2']], 'threshold' => 2]];
        yield 'distinct' => [['version' => 1, 'metric' => 'distinct', 'kind' => 'game_session', 'key' => 'game_system', 'threshold' => 3]];
        yield 'years' => [['version' => 1, 'metric' => 'years', 'basis' => 'account', 'threshold' => 5]];
        yield 'manual' => [['version' => 1, 'metric' => 'manual']];
        yield 'count per season' => [['version' => 1, 'metric' => 'count', 'kind' => 'game_session', 'threshold' => 10, 'window' => ['type' => 'season']]];
        yield 'distinct per season' => [['version' => 1, 'metric' => 'distinct', 'kind' => 'game_session', 'key' => 'game_system', 'threshold' => 3, 'window' => ['type' => 'season']]];
        yield 'streak' => [['version' => 1, 'metric' => 'count', 'kind' => 'game_session', 'threshold' => 1, 'window' => ['type' => 'streak', 'months' => 3]]];
        yield 'streak per season' => [['version' => 1, 'metric' => 'count', 'kind' => 'event', 'threshold' => 2, 'window' => ['type' => 'streak', 'months' => 12, 'per_season' => true]]];
        yield 'manual per season' => [['version' => 1, 'metric' => 'manual', 'window' => ['type' => 'season']]];
        yield 'people' => [['version' => 1, 'metric' => 'people', 'kind' => 'game_session', 'filters' => ['role' => 'director', 'with_role' => 'player'], 'threshold' => 20]];
        yield 'people without roles' => [['version' => 1, 'metric' => 'people', 'kind' => 'event', 'threshold' => 3]];
    }

    public function test_a_rule_without_a_window_counts_every_fact_once(): void
    {
        $rule = AchievementRule::tryFrom(['version' => 1, 'metric' => 'count', 'kind' => 'event', 'threshold' => 5]);

        $this->assertInstanceOf(AchievementRule::class, $rule);
        $this->assertSame(WindowType::AllTime, $rule->window->type);
        $this->assertFalse($rule->isSeasonal());
        $this->assertSame(5, $rule->target());
    }

    public function test_a_season_window_repeats_every_season(): void
    {
        $rule = AchievementRule::count('game_session', 10, window: RuleWindow::season());

        $this->assertInstanceOf(AchievementRule::class, $rule);
        $this->assertSame(WindowType::Season, $rule->window->type);
        $this->assertTrue($rule->isSeasonal());
        $this->assertSame(10, $rule->target());
        $this->assertSame(['type' => 'season'], $rule->toArray()['window']);
    }

    public function test_a_streak_is_measured_in_months_and_repeats_only_per_season(): void
    {
        $once = AchievementRule::count('game_session', 2, window: RuleWindow::streak(6));
        $yearly = AchievementRule::count('game_session', 1, window: RuleWindow::streak(4, perSeason: true));

        $this->assertInstanceOf(AchievementRule::class, $once);
        $this->assertInstanceOf(AchievementRule::class, $yearly);
        $this->assertFalse($once->isSeasonal());
        $this->assertTrue($yearly->isSeasonal());
        $this->assertSame(6, $once->target());
        $this->assertSame(2, $once->threshold);
        $this->assertSame(['type' => 'streak', 'months' => 6], $once->toArray()['window']);
        $this->assertSame(['type' => 'streak', 'months' => 4, 'per_season' => true], $yearly->toArray()['window']);
    }

    public function test_a_manual_rule_may_repeat_per_season(): void
    {
        $rule = AchievementRule::manual(perSeason: true);

        $this->assertTrue($rule->isSeasonal());
        $this->assertSame(['version' => 1, 'metric' => 'manual', 'window' => ['type' => 'season']], $rule->toArray());
        $this->assertSame(['version' => 1, 'metric' => 'manual'], AchievementRule::manual()->toArray());
    }

    public function test_streak_lengths_out_of_range_build_nothing(): void
    {
        $this->assertNull(RuleWindow::streak(1));
        $this->assertNull(RuleWindow::streak(37));
        $this->assertNull(RuleWindow::streak(13, perSeason: true));
        $this->assertInstanceOf(RuleWindow::class, RuleWindow::streak(36));
        $this->assertInstanceOf(RuleWindow::class, RuleWindow::streak(12, perSeason: true));
    }

    #[DataProvider('unreadableRules')]
    public function test_anything_it_does_not_understand_is_unreadable(mixed $rule): void
    {
        $this->assertNull(AchievementRule::tryFrom($rule));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unreadableRules(): iterable
    {
        $count = ['version' => 1, 'metric' => 'count', 'kind' => 'game_session', 'threshold' => 10];

        yield 'null' => [null];
        yield 'empty' => [[]];
        yield 'a list' => [[1, 2]];
        yield 'broken JSON' => ['{"version":1,'];
        yield 'a number' => [12];
        yield 'no version' => [array_diff_key($count, ['version' => true])];
        yield 'another version' => [['version' => 2] + $count];
        yield 'version as text' => [['version' => '1'] + $count];
        yield 'unknown metric' => [['metric' => 'community'] + $count];
        yield 'people of a kind done alone' => [['version' => 1, 'metric' => 'people', 'kind' => 'forum_post', 'threshold' => 3]];
        yield 'people of an unknown kind' => [['version' => 1, 'metric' => 'people', 'kind' => 'dragon_slaying', 'threshold' => 3]];
        yield 'people without kind' => [['version' => 1, 'metric' => 'people', 'threshold' => 3]];
        yield 'people as organizers' => [['version' => 1, 'metric' => 'people', 'kind' => 'tournament', 'filters' => ['role' => 'organizer'], 'threshold' => 3]];
        yield 'people with attendees' => [['version' => 1, 'metric' => 'people', 'kind' => 'event', 'filters' => ['with_role' => 'attendee'], 'threshold' => 3]];
        yield 'people with creators' => [['version' => 1, 'metric' => 'people', 'kind' => 'game_session', 'filters' => ['with_role' => 'creator'], 'threshold' => 3]];
        yield 'people with an unknown role' => [['version' => 1, 'metric' => 'people', 'kind' => 'game_session', 'filters' => ['with_role' => 'wizard'], 'threshold' => 3]];
        yield 'people with an attribute' => [['version' => 1, 'metric' => 'people', 'kind' => 'game_session', 'filters' => ['attribute' => ['key' => 'game_system', 'value' => 'pf2']], 'threshold' => 3]];
        yield 'people with a placement' => [['version' => 1, 'metric' => 'people', 'kind' => 'tournament', 'filters' => ['placement_max' => 3], 'threshold' => 3]];
        yield 'with_role on a count' => [['filters' => ['with_role' => 'player']] + $count];
        // Left out of this release (#24): unreadable, so never evaluated
        yield 'mentor' => [['version' => 1, 'metric' => 'mentor', 'threshold' => 1]];
        yield 'unknown kind' => [['kind' => 'dragon_slaying'] + $count];
        yield 'no kind' => [array_diff_key($count, ['kind' => true])];
        yield 'unknown role' => [['filters' => ['role' => 'wizard']] + $count];
        yield 'zero threshold' => [['threshold' => 0] + $count];
        yield 'negative threshold' => [['threshold' => -1] + $count];
        yield 'threshold as text' => [['threshold' => '10'] + $count];
        yield 'huge threshold' => [['threshold' => 1_000_001] + $count];
        yield 'no threshold' => [array_diff_key($count, ['threshold' => true])];
        yield 'zero placement' => [['filters' => ['placement_max' => 0]] + $count];
        yield 'placement as text' => [['filters' => ['placement_max' => '3']] + $count];
        yield 'attribute without value' => [['filters' => ['attribute' => ['key' => 'game_system']]] + $count];
        yield 'attribute with a bad key' => [['filters' => ['attribute' => ['key' => 'Game', 'value' => 'pf2']]] + $count];
        yield 'attribute with a bad value' => [['filters' => ['attribute' => ['key' => 'game_system', 'value' => ' pf2']]] + $count];
        yield 'unknown filter' => [['filters' => ['season' => '2026']] + $count];
        yield 'filters as a list' => [['filters' => ['player']] + $count];
        yield 'unknown key' => [['streak' => 3] + $count];
        yield 'unknown window' => [['window' => ['type' => 'fortnight']] + $count];
        yield 'season window with a length' => [['window' => ['type' => 'season', 'months' => 3]] + $count];
        yield 'streak without months' => [['window' => ['type' => 'streak']] + $count];
        yield 'streak of one month' => [['window' => ['type' => 'streak', 'months' => 1]] + $count];
        yield 'streak too long' => [['window' => ['type' => 'streak', 'months' => 37]] + $count];
        yield 'season streak longer than a season' => [['window' => ['type' => 'streak', 'months' => 13, 'per_season' => true]] + $count];
        yield 'streak months as text' => [['window' => ['type' => 'streak', 'months' => '3']] + $count];
        yield 'per season as text' => [['window' => ['type' => 'streak', 'months' => 3, 'per_season' => 'yes']] + $count];
        yield 'streak with an unknown key' => [['window' => ['type' => 'streak', 'months' => 3, 'weeks' => 2]] + $count];
        yield 'distinct streak' => [['version' => 1, 'metric' => 'distinct', 'kind' => 'game_session', 'key' => 'game_system', 'threshold' => 3, 'window' => ['type' => 'streak', 'months' => 3]]];
        yield 'years per season' => [['version' => 1, 'metric' => 'years', 'basis' => 'account', 'threshold' => 1, 'window' => ['type' => 'season']]];
        yield 'manual streak' => [['version' => 1, 'metric' => 'manual', 'window' => ['type' => 'streak', 'months' => 3]]];
        yield 'people per season' => [['version' => 1, 'metric' => 'people', 'kind' => 'game_session', 'threshold' => 3, 'window' => ['type' => 'season']]];
        yield 'window as text' => [['window' => 'all_time'] + $count];
        yield 'years without basis' => [['version' => 1, 'metric' => 'years', 'threshold' => 1]];
        yield 'years with a kind' => [['version' => 1, 'metric' => 'years', 'basis' => 'account', 'kind' => 'event', 'threshold' => 1]];
        yield 'years with filters' => [['version' => 1, 'metric' => 'years', 'basis' => 'account', 'filters' => ['role' => 'owner'], 'threshold' => 1]];
        yield 'unknown basis' => [['version' => 1, 'metric' => 'years', 'basis' => 'forum', 'threshold' => 1]];
        yield 'manual with a threshold' => [['version' => 1, 'metric' => 'manual', 'threshold' => 1]];
        yield 'count with a basis' => [['basis' => 'account'] + $count];
        yield 'count with a distinct key' => [['key' => 'game_system'] + $count];
    }
}
