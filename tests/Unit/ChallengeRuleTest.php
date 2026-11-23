<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Unit;

use Modules\Achievements\Domain\Challenges\ChallengeMetric;
use Modules\Achievements\Domain\Challenges\ChallengeRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChallengeRuleTest extends TestCase
{
    public function test_it_reads_a_rule_with_every_filter(): void
    {
        $rule = ChallengeRule::tryFrom([
            'version' => 1,
            'metric' => 'subjects',
            'kind' => 'game_session',
            'filters' => [
                'role' => 'player',
                'attribute' => ['key' => 'game_system', 'value' => 'dnd5e', 'label' => 'D&D 5e'],
                'placement_max' => 3,
            ],
            'target' => 40,
        ]);

        $this->assertInstanceOf(ChallengeRule::class, $rule);
        $this->assertSame(ChallengeMetric::Subjects, $rule->metric);
        $this->assertSame('game_session', $rule->kind);
        $this->assertSame(40, $rule->target);
        $this->assertSame('player', $rule->role);
        $this->assertSame('game_system', $rule->attributeKey);
        $this->assertSame('dnd5e', $rule->attributeValue);
        $this->assertSame('D&D 5e', $rule->attributeLabel);
        $this->assertSame(3, $rule->placementMax);
    }

    public function test_it_reads_json_text_and_round_trips(): void
    {
        $rule = ChallengeRule::make(ChallengeMetric::People, 'event', 12, role: 'attendee');

        $this->assertInstanceOf(ChallengeRule::class, $rule);
        $this->assertSame(
            ['version' => 1, 'metric' => 'people', 'kind' => 'event', 'filters' => ['role' => 'attendee'], 'target' => 12],
            $rule->toArray(),
        );
        $this->assertEquals($rule, ChallengeRule::tryFrom((string) json_encode($rule->toArray())));
    }

    public function test_a_rule_without_filters_has_none(): void
    {
        $rule = ChallengeRule::tryFrom(['version' => 1, 'metric' => 'participations', 'kind' => 'game_session', 'target' => 5]);

        $this->assertInstanceOf(ChallengeRule::class, $rule);
        $this->assertNull($rule->role);
        $this->assertNull($rule->attributeKey);
        $this->assertNull($rule->placementMax);
        $this->assertArrayNotHasKey('filters', $rule->toArray());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unreadable(): iterable
    {
        $valid = ['version' => 1, 'metric' => 'subjects', 'kind' => 'game_session', 'target' => 40];

        yield 'not an array' => ['nonsense'];
        yield 'broken json' => ['{"version":1'];
        yield 'a list' => [[1, 2]];
        yield 'another version' => [['version' => 2] + $valid];
        yield 'an unknown metric' => [['metric' => 'streak'] + $valid];
        yield 'an unknown kind' => [['kind' => 'dance'] + $valid];
        yield 'an unexpected key' => [$valid + ['window' => ['type' => 'all_time']]];
        yield 'a target of 0' => [['target' => 0] + $valid];
        yield 'a target too big' => [['target' => 1_000_001] + $valid];
        yield 'a target as text' => [['target' => '40'] + $valid];
        yield 'an unknown role' => [$valid + ['filters' => ['role' => 'dancer']]];
        yield 'an unknown filter' => [$valid + ['filters' => ['colour' => 'red']]];
        yield 'a placement of 0' => [$valid + ['filters' => ['placement_max' => 0]]];
        yield 'an attribute without value' => [$valid + ['filters' => ['attribute' => ['key' => 'game_system']]]];
    }

    #[DataProvider('unreadable')]
    public function test_anything_it_does_not_understand_is_unreadable(mixed $raw): void
    {
        $this->assertNull(ChallengeRule::tryFrom($raw));
    }
}
