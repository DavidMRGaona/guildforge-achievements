<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Integration\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\ValueObjects\MetricKinds;
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

    public function test_an_unreadable_row_gives_the_defaults(): void
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
        $this->assertTrue($service->metricKindEnabled(MetricKinds::EVENT));
    }

    private function service(): AchievementsSettingsInterface
    {
        return $this->app->make(AchievementsSettingsInterface::class);
    }
}
