<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Integration\Infrastructure;

use App\Application\Services\SettingsServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\ValueObjects\MetricKinds;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;

final class ModuleAchievementsSettingsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    public function test_nothing_saved_gives_every_default(): void
    {
        $service = $this->service();

        $this->assertTrue($service->progressEnabled());
        $this->assertTrue($service->rarityEnabled());
        $this->assertTrue($service->secretsEnabled());
        $this->assertTrue($service->seasonsAndStreaksEnabled());
        $this->assertTrue($service->communityEnabled());
        $this->assertTrue($service->bulkGrantsEnabled());
        $this->assertTrue($service->announcementsEnabled());
        $this->assertSame(10.0, $service->announcementsRarityThreshold());
        $this->assertFalse($service->pointsEnabled());
        $this->assertFalse($service->levelsEnabled());
        $this->assertFalse($service->leaderboardEnabled());
        $this->assertFalse($service->recognitionsEnabled());
        $this->assertSame(14, $service->recognitionsWindowDays());
        $this->assertFalse($service->challengesEnabled());
        $this->assertSame(MetricKinds::ALL, array_keys($service->metricKinds()));
        $this->assertNotContains(false, $service->metricKinds());
        $this->assertTrue($service->metricKindEnabled(MetricKinds::TOURNAMENT));
    }

    public function test_it_reads_back_what_it_saved(): void
    {
        $this->service()->save(new AchievementsSettings(
            progress: false,
            announcementsRarityThreshold: 5.5,
            points: true,
            levels: true,
            recognitions: true,
            recognitionsWindowDays: 7,
            challenges: true,
            metricKinds: [MetricKinds::FORUM_POST => false],
        ));

        // A new instance, as the next request would have
        $this->app->forgetScopedInstances();
        $service = $this->app->make(AchievementsSettingsInterface::class);

        $this->assertFalse($service->progressEnabled());
        $this->assertSame(5.5, $service->announcementsRarityThreshold());
        $this->assertTrue($service->pointsEnabled());
        $this->assertTrue($service->levelsEnabled());
        $this->assertTrue($service->recognitionsEnabled());
        $this->assertSame(7, $service->recognitionsWindowDays());
        $this->assertTrue($service->challengesEnabled());
        $this->assertFalse($service->metricKindEnabled(MetricKinds::FORUM_POST));
        $this->assertTrue($service->metricKindEnabled(MetricKinds::EVENT));
        $this->assertTrue($service->rarityEnabled());
    }

    public function test_saving_replaces_the_copy_read_before(): void
    {
        $service = $this->service();
        $this->assertTrue($service->progressEnabled());

        $service->save(new AchievementsSettings(progress: false));

        $this->assertFalse($service->progressEnabled());
    }

    public function test_the_values_are_stored_in_the_row_of_the_module(): void
    {
        $this->service()->save(new AchievementsSettings(points: true));

        $row = DB::table('settings')->where('key', 'module_settings:achievements')->value('value');
        $stored = json_decode((string) $row, true);

        $this->assertIsArray($stored);
        $this->assertTrue($stored['points']);
        $this->assertSame(14, $stored['recognitions_window_days']);
    }

    public function test_malformed_values_take_their_safe_default(): void
    {
        DB::table('settings')->insert([
            'id' => (string) Str::uuid(),
            'key' => 'module_settings:achievements',
            'value' => json_encode(['progress' => 'maybe', 'announcements_rarity_threshold' => 'lots', 'metric_kinds' => 'none']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = $this->service();

        $this->assertTrue($service->progressEnabled());
        $this->assertSame(10.0, $service->announcementsRarityThreshold());
        // A broken map never switches on what may have been off
        $this->assertFalse($service->metricKindEnabled(MetricKinds::EVENT));
    }

    public function test_the_strict_read_warns_once_about_malformed_values(): void
    {
        DB::table('settings')->insert(['id' => (string) Str::uuid(), 'key' => 'module_settings:achievements', 'value' => '{"metric_kinds":"none"}', 'created_at' => now(), 'updated_at' => now()]);
        $log = Log::spy();

        $settings = $this->service()->readStrictly();
        $this->app->forgetScopedInstances();
        $this->app->make(AchievementsSettingsInterface::class)->readStrictly();

        $this->assertInstanceOf(AchievementsSettings::class, $settings);
        $this->assertFalse($settings->metricKindEnabled(MetricKinds::GAME_SESSION));
        $log->shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => $context === ['keys' => ['metric_kinds'], 'reason' => 'settings_malformed'],
        );
    }

    public function test_a_failed_strict_read_is_remembered_until_saving(): void
    {
        // once(): the second read must not reach the table
        $this->mock(SettingsServiceInterface::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('getStored')->once()->andThrow(new RuntimeException('The database is away.'));
        });
        $this->app->forgetScopedInstances();
        $service = $this->app->make(AchievementsSettingsInterface::class);

        $this->assertNull($service->readStrictly());
        $this->assertNull($service->readStrictly());
    }

    public function test_the_strict_read_gives_the_defaults_when_nothing_was_ever_saved(): void
    {
        $settings = $this->service()->readStrictly();

        $this->assertInstanceOf(AchievementsSettings::class, $settings);
        $this->assertEquals(new AchievementsSettings, $settings);
    }

    public function test_the_strict_read_gives_what_was_saved(): void
    {
        $this->service()->save(new AchievementsSettings(secrets: false, metricKinds: [MetricKinds::EVENT => false]));
        $this->app->forgetScopedInstances();

        $settings = $this->app->make(AchievementsSettingsInterface::class)->readStrictly();

        $this->assertInstanceOf(AchievementsSettings::class, $settings);
        $this->assertFalse($settings->secrets);
        $this->assertFalse($settings->metricKindEnabled(MetricKinds::EVENT));
    }

    public function test_the_strict_read_reads_the_table_not_the_cache(): void
    {
        $this->service()->settings();
        DB::table('settings')->insert(['id' => (string) Str::uuid(), 'key' => 'module_settings:achievements', 'value' => '{"secrets":false}', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->forgetScopedInstances();

        $settings = $this->app->make(AchievementsSettingsInterface::class)->readStrictly();

        $this->assertInstanceOf(AchievementsSettings::class, $settings);
        $this->assertFalse($settings->secrets);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableValues(): iterable
    {
        yield 'broken JSON' => ['{"secrets":'];
        yield 'a list' => ['[true, false]'];
        yield 'a number' => ['12'];
        yield 'a string' => ['"on"'];
    }

    #[DataProvider('unreadableValues')]
    public function test_a_stored_value_that_is_not_an_object_cannot_be_read_strictly(string $value): void
    {
        DB::table('settings')->insert(['id' => (string) Str::uuid(), 'key' => 'module_settings:achievements', 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNull($this->service()->readStrictly());
        // The tolerant read still answers with the defaults, for the pages
        $this->assertTrue($this->service()->secretsEnabled());
    }

    public function test_an_unreachable_table_cannot_be_read_strictly(): void
    {
        // Renaming the table would abort PostgreSQL's test transaction: the core's read throws instead
        $this->mock(SettingsServiceInterface::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('getStored')->andThrow(new RuntimeException('The database is away.'));
        });
        $this->app->forgetScopedInstances();

        $this->assertNull($this->app->make(AchievementsSettingsInterface::class)->readStrictly());
    }

    private function service(): AchievementsSettingsInterface
    {
        return $this->app->make(AchievementsSettingsInterface::class);
    }
}
