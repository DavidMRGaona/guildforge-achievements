<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Unit;

use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Domain\ValueObjects\MetricKinds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AchievementsSettingsTest extends TestCase
{
    public function test_the_defaults(): void
    {
        $settings = new AchievementsSettings;

        $this->assertTrue($settings->progress);
        $this->assertTrue($settings->rarity);
        $this->assertTrue($settings->secrets);
        $this->assertTrue($settings->seasonsAndStreaks);
        $this->assertTrue($settings->community);
        $this->assertFalse($settings->bulkGrants);
        $this->assertTrue($settings->announcements);
        $this->assertSame(10.0, $settings->announcementsRarityThreshold);
        $this->assertFalse($settings->points);
        $this->assertFalse($settings->levels);
        $this->assertFalse($settings->leaderboard);
        $this->assertFalse($settings->recognitions);
        $this->assertSame(14, $settings->recognitionsWindowDays);
        $this->assertFalse($settings->challenges);
    }

    public function test_reading_nothing_gives_the_defaults(): void
    {
        $this->assertEquals(new AchievementsSettings, AchievementsSettings::fromArray([]));
    }

    public function test_it_reads_back_what_it_writes(): void
    {
        $settings = new AchievementsSettings(
            progress: false,
            announcementsRarityThreshold: 2.5,
            points: true,
            recognitions: true,
            recognitionsWindowDays: 30,
            metricKinds: [MetricKinds::FORUM_POST => false],
        );

        $this->assertEquals($settings, AchievementsSettings::fromArray($settings->toArray()));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badThresholds(): array
    {
        return [
            'a string' => ['10'],
            'null' => [null],
            'zero' => [0],
            'negative' => [-5],
            'over one hundred' => [150],
            'a list' => [[10]],
        ];
    }

    #[DataProvider('badThresholds')]
    public function test_a_threshold_that_is_not_a_percentage_takes_its_default(mixed $value): void
    {
        $this->assertSame(10.0, AchievementsSettings::fromArray(['announcements_rarity_threshold' => $value])->announcementsRarityThreshold);
    }

    public function test_an_integer_threshold_is_read_as_a_number(): void
    {
        $this->assertSame(5.0, AchievementsSettings::fromArray(['announcements_rarity_threshold' => 5])->announcementsRarityThreshold);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badWindows(): array
    {
        return [
            'a string' => ['14'],
            'a float' => [3.5],
            'zero' => [0],
            'a year and a day' => [366],
            'null' => [null],
        ];
    }

    #[DataProvider('badWindows')]
    public function test_a_window_that_is_not_a_number_of_days_takes_its_default(mixed $value): void
    {
        $this->assertSame(14, AchievementsSettings::fromArray(['recognitions_window_days' => $value])->recognitionsWindowDays);
    }

    public function test_a_switch_that_is_not_a_boolean_takes_its_default(): void
    {
        $settings = AchievementsSettings::fromArray(['progress' => 'no', 'points' => 1, 'rarity' => null]);

        $this->assertTrue($settings->progress);
        $this->assertFalse($settings->points);
        $this->assertTrue($settings->rarity);
    }

    public function test_a_kind_with_no_switch_is_on(): void
    {
        $settings = AchievementsSettings::fromArray(['metric_kinds' => [MetricKinds::EVENT => false]]);

        $this->assertFalse($settings->metricKindEnabled(MetricKinds::EVENT));
        $this->assertTrue($settings->metricKindEnabled(MetricKinds::GAME_SESSION));
        $this->assertTrue($settings->metricKindEnabled('a_kind_of_a_newer_core'));
    }

    public function test_a_kind_switch_that_is_not_a_boolean_turns_that_kind_off(): void
    {
        $settings = AchievementsSettings::fromArray(['metric_kinds' => ['event' => 'no', 0 => true, '' => false, 'campaign' => true]]);

        $this->assertSame(['event' => false, 'campaign' => true], $settings->metricKinds);
        $this->assertFalse($settings->metricKindEnabled('event'));
        $this->assertSame(['metric_kinds'], $settings->invalidKeys);
    }

    public function test_kind_switches_that_are_not_a_map_turn_every_kind_off(): void
    {
        foreach (['all', true, ['game_session', 'event']] as $value) {
            $settings = AchievementsSettings::fromArray(['metric_kinds' => $value]);

            $this->assertNotContains(true, $settings->allMetricKinds());
            $this->assertSame(['metric_kinds'], $settings->invalidKeys);
        }

        $this->assertSame([], AchievementsSettings::fromArray(['metric_kinds' => []])->invalidKeys);
        $this->assertSame([], AchievementsSettings::fromArray([])->invalidKeys);
    }

    public function test_a_malformed_bulk_grants_switch_is_off(): void
    {
        $settings = AchievementsSettings::fromArray(['bulk_grants' => 'yes']);

        $this->assertFalse($settings->bulkGrants);
        $this->assertSame(['bulk_grants'], $settings->invalidKeys);
        $this->assertTrue(AchievementsSettings::fromArray(['bulk_grants' => true])->bulkGrants);
    }

    public function test_a_malformed_secrets_switch_is_off_and_other_switches_keep_their_default(): void
    {
        $settings = AchievementsSettings::fromArray(['secrets' => 'yes', 'progress' => 1, 'points' => 'on']);

        $this->assertFalse($settings->secrets);
        $this->assertTrue($settings->progress);
        $this->assertFalse($settings->points);
        $this->assertSame(['progress', 'secrets', 'points'], $settings->invalidKeys);
    }

    public function test_every_known_kind_is_listed_followed_by_the_stored_unknown_ones(): void
    {
        $kinds = AchievementsSettings::fromArray(['metric_kinds' => ['future_kind' => false, 'event' => false]])->allMetricKinds();

        $this->assertSame([...MetricKinds::ALL, 'future_kind'], array_keys($kinds));
        $this->assertFalse($kinds['event']);
        $this->assertTrue($kinds['campaign']);
        $this->assertFalse($kinds['future_kind']);
    }

    public function test_the_kinds_are_the_ones_of_the_activity_enum(): void
    {
        foreach (['game_session', 'campaign', 'tournament', 'event', 'forum_post', 'forum_discussion', 'membership_year', 'survey_response', 'venue_booking', 'account', 'profile_completed', 'proposal'] as $kind) {
            $this->assertTrue(MetricKinds::isKnown($kind), $kind);
            $this->assertContains($kind, MetricKinds::ALL);
        }

        $this->assertFalse(MetricKinds::isKnown('chess'));
    }
}
