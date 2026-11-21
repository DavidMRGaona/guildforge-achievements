<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Ledger;

use App\Application\Activity\DTOs\ActivityHistoryPageDTO;
use App\Application\Activity\DTOs\ActivitySubjectFactsDTO;
use App\Domain\Activity\ActivityRole;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\PendingCommand;
use Modules\Achievements\Console\Commands\RecalculateAchievementFactsCommand;
use Modules\Achievements\Domain\Events\AchievementFactsChanged;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\MetricKinds;
use Modules\Achievements\Infrastructure\Services\ActivityLedger;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\ScriptedActivitySource;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;

final class RecalculateCommandTest extends ModuleTestCase
{
    use BuildsActivityFacts;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        ScriptedActivitySource::reset();
        $this->registerInMemorySource();
        Event::fake([AchievementFactsChanged::class]);
        Notification::fake();

        // The module is enabled after the console booted, so its provider's commands are not in it yet
        $this->app->make(Kernel::class)->registerCommand($this->app->make(RecalculateAchievementFactsCommand::class));
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();
        ScriptedActivitySource::reset();

        parent::tearDown();
    }

    public function test_it_walks_the_whole_history_page_by_page(): void
    {
        $this->putSubjects(ActivityLedger::HISTORY_PAGE_SIZE + 3);

        $this->recalculate(['--source' => InMemoryActivitySource::TYPE])
            ->expectsOutputToContain(InMemoryActivitySource::TYPE.': leídos: '.(ActivityLedger::HISTORY_PAGE_SIZE + 3))
            ->assertExitCode(Command::SUCCESS);

        $this->assertCount(ActivityLedger::HISTORY_PAGE_SIZE + 3, $this->ledger()->subjectIds(InMemoryActivitySource::TYPE));
        $this->assertSame(MetricKinds::GAME_SESSION, $this->ledger()->forUser(self::ANA)[0]->kind);
    }

    public function test_every_source_is_walked_when_none_is_named(): void
    {
        $this->registerSource(ScriptedActivitySource::class);
        InMemoryActivitySource::put('42', [$this->activityFact(self::ANA)]);
        ScriptedActivitySource::putPage(null, new ActivityHistoryPageDTO([
            new ActivitySubjectFactsDTO('7', [$this->activityFact(self::LUIS, ActivityRole::Organizer)]),
        ], null));

        $this->recalculate()->assertExitCode(Command::SUCCESS);

        $this->assertSame(['42'], $this->ledger()->subjectIds(InMemoryActivitySource::TYPE));
        $this->assertSame(['7'], $this->ledger()->subjectIds(ScriptedActivitySource::TYPE));
        $this->assertSame(MetricKinds::TOURNAMENT, $this->ledger()->forUser(self::LUIS)[0]->kind);
    }

    public function test_it_is_quiet_and_tells_the_evaluator_to_stay_quiet(): void
    {
        InMemoryActivitySource::put('42', [$this->activityFact(self::ANA), $this->activityFact(self::LUIS)]);

        $this->recalculate()->assertExitCode(Command::SUCCESS);

        Notification::assertNothingSent();
        Event::assertDispatched(
            AchievementFactsChanged::class,
            static fn (AchievementFactsChanged $event): bool => $event->silent && $event->userIds === [self::ANA, self::LUIS],
        );
    }

    public function test_running_it_again_changes_nothing(): void
    {
        InMemoryActivitySource::put('42', [$this->activityFact(self::ANA, system: 'pf2')]);
        InMemoryActivitySource::put('43', [$this->activityFact(self::LUIS)]);
        $this->recalculate()->assertExitCode(Command::SUCCESS);
        $before = DB::table('achievements_facts')->orderBy('id')->get()->all();

        $this->recalculate()
            ->expectsOutputToContain('leídos: 2 · actualizados: 0 · retirados: 0')
            ->assertExitCode(Command::SUCCESS);

        $this->assertEquals($before, DB::table('achievements_facts')->orderBy('id')->get()->all());
    }

    public function test_a_stored_subject_missing_from_a_complete_history_is_removed(): void
    {
        $this->ledger()->replaceSubject(InMemoryActivitySource::TYPE, '41', MetricKinds::GAME_SESSION, [$this->recordedFact(self::MARTA)]);
        InMemoryActivitySource::put('42', [$this->activityFact(self::ANA)]);

        $this->recalculate()
            ->expectsOutputToContain('leídos: 1 · actualizados: 1 · retirados: 1')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(['42'], $this->ledger()->subjectIds(InMemoryActivitySource::TYPE));
        Event::assertDispatched(
            AchievementFactsChanged::class,
            static fn (AchievementFactsChanged $event): bool => in_array(self::MARTA, $event->userIds, true),
        );
    }

    public function test_a_failed_page_stops_the_source_without_removing_anything(): void
    {
        $this->registerSource(ScriptedActivitySource::class);
        $this->ledger()->replaceSubject(ScriptedActivitySource::TYPE, 'old', MetricKinds::TOURNAMENT, [$this->recordedFact(self::MARTA, 'organizer')]);
        ScriptedActivitySource::putPage(null, new ActivityHistoryPageDTO([
            new ActivitySubjectFactsDTO('7', [$this->activityFact(self::LUIS, ActivityRole::Organizer)]),
        ], 'page-2'));
        ScriptedActivitySource::putPage('page-2', null);

        $this->recalculate(['--source' => ScriptedActivitySource::TYPE])
            ->expectsOutputToContain('ha fallado')
            ->assertExitCode(Command::FAILURE);

        // What was read is kept, and nothing is taken away
        $this->assertSame(['7', 'old'], $this->ledger()->subjectIds(ScriptedActivitySource::TYPE));
    }

    public function test_a_history_that_comes_back_to_a_page_already_read_stops_without_removing_anything(): void
    {
        $this->registerSource(ScriptedActivitySource::class);
        $this->ledger()->replaceSubject(ScriptedActivitySource::TYPE, 'old', MetricKinds::TOURNAMENT, [$this->recordedFact(self::MARTA, 'organizer')]);
        ScriptedActivitySource::putPage(null, new ActivityHistoryPageDTO([
            new ActivitySubjectFactsDTO('7', [$this->activityFact(self::LUIS, ActivityRole::Organizer)]),
        ], 'a'));
        ScriptedActivitySource::putPage('a', new ActivityHistoryPageDTO([
            new ActivitySubjectFactsDTO('8', [$this->activityFact(self::ANA, ActivityRole::Organizer)]),
        ], 'b'));
        ScriptedActivitySource::putPage('b', new ActivityHistoryPageDTO([
            new ActivitySubjectFactsDTO('9', [$this->activityFact(self::ANA, ActivityRole::Organizer)]),
        ], 'a'));

        $this->recalculate(['--source' => ScriptedActivitySource::TYPE])
            ->expectsOutputToContain('ya leída')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(['7', '8', '9', 'old'], $this->ledger()->subjectIds(ScriptedActivitySource::TYPE));
    }

    public function test_one_failing_source_does_not_stop_the_others(): void
    {
        $this->registerSource(ScriptedActivitySource::class);
        InMemoryActivitySource::put('42', [$this->activityFact(self::ANA)]);

        $this->recalculate()->assertExitCode(Command::FAILURE);

        $this->assertSame(['42'], $this->ledger()->subjectIds(InMemoryActivitySource::TYPE));
    }

    public function test_a_source_nobody_offers_is_left_as_it_was(): void
    {
        $this->ledger()->replaceSubject('gone-module.thing', '42', MetricKinds::GAME_SESSION, [$this->recordedFact(self::ANA)]);

        $this->recalculate(['--source' => 'gone-module.thing'])
            ->expectsOutputToContain('no hay ninguna fuente')
            ->assertExitCode(Command::FAILURE);
        $this->recalculate()->assertExitCode(Command::SUCCESS);

        $this->assertSame(['42'], $this->ledger()->subjectIds('gone-module.thing'));
    }

    public function test_a_dry_run_counts_without_writing_or_telling_anybody(): void
    {
        $this->ledger()->replaceSubject(InMemoryActivitySource::TYPE, '41', MetricKinds::GAME_SESSION, [$this->recordedFact(self::MARTA)]);
        InMemoryActivitySource::put('42', [$this->activityFact(self::ANA)]);

        $this->recalculate(['--dry-run' => true])
            ->expectsOutputToContain('leídos: 1 · actualizados: 1 · retirados: 1')
            ->expectsOutputToContain('Simulación')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(['41'], $this->ledger()->subjectIds(InMemoryActivitySource::TYPE));
        Event::assertNotDispatched(AchievementFactsChanged::class);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function recalculate(array $options = []): PendingCommand
    {
        $command = $this->artisan('achievements:recalculate', $options);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function putSubjects(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            InMemoryActivitySource::put(sprintf('s%04d', $i), [$this->activityFact(self::ANA)]);
        }
    }

    private function ledger(): AchievementFactRepositoryInterface
    {
        return $this->app->make(AchievementFactRepositoryInterface::class);
    }
}
