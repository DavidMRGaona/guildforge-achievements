<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Catalogue;

use App\Application\Profiles\ProfileBadgeIcons;
use App\Domain\Profiles\ProfileBadgeColour;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\PendingCommand;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Application\Services\StarterCatalogueInterface;
use Modules\Achievements\Console\Commands\InstallStarterCatalogueCommand;
use Modules\Achievements\Database\Seeders\StarterCatalogueSeeder;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;

final class StarterCatalogueTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutStarterCatalogue();
        $this->registerInMemorySource();
        Bus::fake([EvaluateAchievementsJob::class]);
        $this->app->make(Kernel::class)->registerCommand($this->app->make(InstallStarterCatalogueCommand::class));
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();

        parent::tearDown();
    }

    public function test_installing_the_module_seeds_it(): void
    {
        new StarterCatalogueSeeder()->run();

        $this->assertSame(count($this->catalogue()->keys()), AchievementModel::query()->count());
    }

    public function test_it_holds_the_agreed_achievements_with_readable_rules_and_core_looks(): void
    {
        $this->catalogue()->install();

        $this->assertSame([
            'welcome_first_game', 'welcome_profile_completed', 'welcome_first_forum_post',
            'games_played_1', 'games_played_10', 'games_played_50', 'games_played_100',
            'games_run_1', 'games_run_10', 'systems_3', 'systems_10', 'campaign_completed',
            'tournament_first', 'tournament_podium', 'events_5', 'years_1', 'years_3', 'years_5',
            'first_proposal', 'first_table_played', 'people_played_10', 'people_run_20', 'mentor',
        ], $this->catalogue()->keys());

        foreach (AchievementModel::query()->get() as $achievement) {
            $this->assertInstanceOf(AchievementRule::class, AchievementRule::tryFrom($achievement->getRawOriginal('rule')), (string) $achievement->starter_key);
            $this->assertContains($achievement->icon, ProfileBadgeIcons::NAMES);
            $this->assertInstanceOf(ProfileBadgeColour::class, ProfileBadgeColour::tryFrom($achievement->colour));
            $this->assertFalse($achievement->is_secret);
            $this->assertLessThanOrEqual(60, mb_strlen($achievement->name));
            $this->assertNotSame('', $achievement->description);
            $this->assertStringNotContainsString('achievements::', $achievement->name);
        }

        $this->assertSame('10 partidas', $this->byKey('games_played_10')->name);
        $this->assertSame(
            ['version' => 1, 'metric' => 'count', 'kind' => 'tournament', 'filters' => ['role' => 'player', 'placement_max' => 3], 'threshold' => 1, 'window' => ['type' => 'all_time']],
            $this->byKey('tournament_podium')->rule,
        );
        $this->assertSame(['version' => 1, 'metric' => 'years', 'basis' => 'account', 'threshold' => 5], $this->byKey('years_5')->rule);
        $this->assertSame(
            ['version' => 1, 'metric' => 'people', 'kind' => 'game_session', 'filters' => ['role' => 'player', 'with_role' => 'player'], 'threshold' => 10, 'window' => ['type' => 'all_time']],
            $this->byKey('people_played_10')->rule,
        );
        $this->assertSame(
            ['version' => 1, 'metric' => 'people', 'kind' => 'game_session', 'filters' => ['role' => 'director', 'with_role' => 'player'], 'threshold' => 20, 'window' => ['type' => 'all_time']],
            $this->byKey('people_run_20')->rule,
        );
        $this->assertSame(['version' => 1, 'metric' => 'mentor', 'threshold' => 1, 'window' => ['type' => 'all_time']], $this->byKey('mentor')->rule);
    }

    public function test_only_achievements_whose_kind_has_a_source_start_active(): void
    {
        $this->catalogue()->install();
        // Whatever sources the core and the test offer: the switch follows them
        $metrics = $this->app->make(ActivityMetricsInterface::class);

        foreach (AchievementModel::query()->get() as $achievement) {
            $kind = AchievementRule::tryFrom($achievement->getRawOriginal('rule'))?->evaluatedKind();
            $this->assertNotNull($kind);
            $this->assertSame($metrics->hasSource($kind), $achievement->is_active, (string) $achievement->starter_key);
        }

        $this->assertTrue($this->byKey('games_played_10')->is_active);
        $this->assertFalse($metrics->hasSource('event'));
        $this->assertFalse($this->byKey('events_5')->is_active);
    }

    public function test_installing_again_adds_only_what_is_missing_and_keeps_what_was_edited(): void
    {
        $this->catalogue()->install();
        $this->byKey('games_played_10')->update(['name' => 'Diez partidas', 'is_active' => false]);
        $this->byKey('events_5')->delete();

        $created = $this->catalogue()->install();

        $this->assertSame(['events_5'], $created);
        $this->assertSame(count($this->catalogue()->keys()), AchievementModel::query()->count());
        $this->assertSame('Diez partidas', $this->byKey('games_played_10')->name);
        $this->assertFalse($this->byKey('games_played_10')->is_active);
    }

    public function test_a_deleted_starter_achievement_comes_back_switched_off(): void
    {
        $this->catalogue()->install();
        $this->assertTrue($this->byKey('games_played_10')->is_active);
        $this->byKey('games_played_10')->delete();

        $this->catalogue()->install();

        $this->assertFalse($this->byKey('games_played_10')->is_active);
    }

    public function test_installing_queues_one_evaluation_for_every_active_achievement_it_created(): void
    {
        $this->catalogue()->install();

        Bus::assertDispatchedTimes(EvaluateAchievementsJob::class, 1);
        $active = AchievementModel::query()->where('is_active', true)->pluck('id')->all();
        Bus::assertDispatched(EvaluateAchievementsJob::class, static fn (EvaluateAchievementsJob $job): bool => $job->silent && $job->achievementIds !== null && array_diff($active, $job->achievementIds) === [] && count($job->achievementIds) === count($active));
    }

    public function test_the_command_reports_what_it_created(): void
    {
        $this->install()
            ->expectsOutputToContain('23 logros creados · 0 ya estaban')
            ->assertExitCode(Command::SUCCESS);

        $this->install()
            ->expectsOutputToContain('0 logros creados · 23 ya estaban')
            ->assertExitCode(Command::SUCCESS);
    }

    private function install(): PendingCommand
    {
        $command = $this->artisan('achievements:install-starter-catalogue');
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function byKey(string $key): AchievementModel
    {
        return AchievementModel::query()->where('starter_key', $key)->sole();
    }

    private function catalogue(): StarterCatalogueInterface
    {
        return $this->app->make(StarterCatalogueInterface::class);
    }
}
