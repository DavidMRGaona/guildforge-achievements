<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Grants;

use App\Domain\Activity\Events\ActivityRecorded;
use App\Domain\Events\UserAnonymized;
use App\Domain\Modules\Events\ModuleEnabled;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Achievements\Application\Exceptions\AchievementSettingsUnreadableException;
use Modules\Achievements\Domain\Events\AchievementFactsChanged;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Listeners\EvaluateAchievementsForChangedFacts;
use Modules\Achievements\Listeners\ForgetAnonymizedAccountGrants;
use Modules\Achievements\Tests\Support\AccountActivitySource;
use Modules\Achievements\Tests\Support\BrokenTournamentSource;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;

final class GrantPipelineTest extends ModuleTestCase
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
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();

        parent::tearDown();
    }

    public function test_the_listener_runs_on_the_queue_after_commit(): void
    {
        $this->assertContains(ShouldQueue::class, class_implements(EvaluateAchievementsForChangedFacts::class));
        $this->assertTrue($this->app->make(EvaluateAchievementsForChangedFacts::class)->afterCommit);
        $this->assertContains(ShouldQueue::class, class_implements(ForgetAnonymizedAccountGrants::class));
        $this->assertTrue($this->app->make(ForgetAnonymizedAccountGrants::class)->afterCommit);
    }

    public function test_a_recorded_subject_ends_in_a_grant_with_a_notice(): void
    {
        // The fact is of 2026-03-14: played two days ago
        $this->travelTo(new \DateTimeImmutable('2026-03-16 12:00:00'));
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule());
        InMemoryActivitySource::put('42', [$this->activityFact($ana)]);

        event(new ActivityRecorded(InMemoryActivitySource::TYPE, '42'));

        $grant = DB::table('achievements_grants')->where('user_id', $ana)->first();
        $this->assertNotNull($grant);
        $this->assertFalse((bool) $grant->silent);
    }

    public function test_a_recalculation_grants_silently(): void
    {
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule());
        $this->ledgerSubject(InMemoryActivitySource::TYPE, '42', 'game_session', [$this->recordedFact($ana)]);

        event(new AchievementFactsChanged([$ana], silent: true));

        $this->assertTrue((bool) DB::table('achievements_grants')->where('user_id', $ana)->value('silent'));
    }

    public function test_the_listener_is_retried_when_the_settings_cannot_be_read(): void
    {
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule());
        DB::table('settings')->insert(['id' => (string) Str::uuid(), 'key' => 'module_settings:achievements', 'value' => '[1]', 'created_at' => now(), 'updated_at' => now()]);
        // A queued job starts with its own scoped instances
        $this->app->forgetScopedInstances();

        $listener = $this->app->make(EvaluateAchievementsForChangedFacts::class)->withFakeQueueInteractions();
        $listener->handle(new AchievementFactsChanged([$ana]));

        $listener->assertReleased(60);
    }

    public function test_the_listener_fails_after_its_last_try_when_the_settings_stay_unreadable(): void
    {
        $ana = $this->account(self::ANA);
        DB::table('settings')->insert(['id' => (string) Str::uuid(), 'key' => 'module_settings:achievements', 'value' => '[1]', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->forgetScopedInstances();

        $listener = $this->app->make(EvaluateAchievementsForChangedFacts::class)->withFakeQueueInteractions();
        $this->assertInstanceOf(FakeJob::class, $listener->job);
        $listener->job->attempts = $listener->tries;
        $listener->handle(new AchievementFactsChanged([$ana]));

        $listener->assertFailedWith(AchievementSettingsUnreadableException::class);
    }

    public function test_the_job_waits_for_a_source_that_is_registered_but_unavailable(): void
    {
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule('tournament'));
        $this->ledgerSubject(BrokenTournamentSource::TYPE, 't1', 'tournament', [$this->recordedFact($ana)]);
        $this->registerSource(BrokenTournamentSource::class);
        $this->app->forgetScopedInstances();

        $job = new EvaluateAchievementsJob()->withFakeQueueInteractions();
        $this->app->call($job->handle(...));
        $job->assertReleased(60);

        // After the last try it ends without failing: the module being enabled evaluates it again
        $last = new EvaluateAchievementsJob()->withFakeQueueInteractions();
        $this->assertInstanceOf(FakeJob::class, $last->job);
        $last->job->attempts = $last->tries;
        $this->app->call($last->handle(...));
        $last->assertNotReleased();
        $last->assertNotFailed();
    }

    public function test_enabling_a_module_evaluates_the_kinds_of_its_sources(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);

        event(new ModuleEnabled('id', 'game-tables'));
        event(new ModuleEnabled('id', 'achievements'));

        Bus::assertDispatchedTimes(EvaluateAchievementsJob::class, 1);
        Bus::assertDispatched(EvaluateAchievementsJob::class, static fn (EvaluateAchievementsJob $job): bool => $job->module === 'game-tables' && $job->silent);
    }

    public function test_the_job_for_a_module_evaluates_only_what_its_sources_offer(): void
    {
        $ana = $this->account(self::ANA);
        $this->registerSource(AccountActivitySource::class);
        $this->achievement($this->countRule());
        $years = $this->achievement(['version' => 1, 'metric' => 'years', 'basis' => 'account', 'threshold' => 1]);
        DB::table('achievements_grants')->delete();
        $this->ledgerSubject(InMemoryActivitySource::TYPE, '1', 'game_session', [$this->recordedFact($ana)]);
        $this->ledgerSubject(AccountActivitySource::TYPE, $ana, 'account', [$this->recordedFact($ana, 'owner', when: '2020-01-01 12:00:00')]);
        $this->app->forgetScopedInstances();

        $this->app->call(new EvaluateAchievementsJob(module: 'achievements')->handle(...));

        $this->assertSame([$years->id], DB::table('achievements_grants')->pluck('achievement_id')->all());
    }

    public function test_the_job_is_unique_while_it_waits_and_has_a_timeout(): void
    {
        $this->assertContains(ShouldBeUnique::class, class_implements(EvaluateAchievementsJob::class));
        $this->assertSame(new EvaluateAchievementsJob(['B', 'a'])->uniqueId(), new EvaluateAchievementsJob(['a', 'b'])->uniqueId());
        $this->assertNotSame(new EvaluateAchievementsJob(['a'])->uniqueId(), new EvaluateAchievementsJob(['a'], silent: false)->uniqueId());
        $this->assertNotSame(new EvaluateAchievementsJob()->uniqueId(), new EvaluateAchievementsJob(module: 'forum')->uniqueId());
        $this->assertGreaterThan(0, new EvaluateAchievementsJob()->timeout);
    }

    public function test_creating_an_achievement_grants_it_silently_to_whoever_already_met_it(): void
    {
        $ana = $this->account(self::ANA);
        $this->ledgerSubject(InMemoryActivitySource::TYPE, '42', 'game_session', [$this->recordedFact($ana)]);

        $achievement = $this->achievement($this->countRule());

        $grant = DB::table('achievements_grants')->where('user_id', $ana)->first();
        $this->assertNotNull($grant);
        $this->assertSame($achievement->id, $grant->achievement_id);
        $this->assertTrue((bool) $grant->silent);
    }

    public function test_changing_the_rule_or_switching_it_on_evaluates_it_again(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $achievement = $this->achievement($this->countRule(), ['is_active' => false]);
        Bus::assertNotDispatched(EvaluateAchievementsJob::class);

        $achievement->update(['is_active' => true]);
        $achievement->update(['rule' => $this->countRule(threshold: 5)]);
        $achievement->update(['name' => 'Otro nombre']);

        // The second one is the same job while the first waits (ShouldBeUnique)
        Bus::assertDispatchedTimes(EvaluateAchievementsJob::class, 1);
        Bus::assertDispatched(EvaluateAchievementsJob::class, static fn (EvaluateAchievementsJob $job): bool => $job->achievementIds === [$achievement->id] && $job->silent);
    }

    public function test_account_years_are_evaluated_every_day_with_notices(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(static fn ($event): bool => $event->description === 'achievements:account-years');

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }

    public function test_an_anonymized_account_loses_its_grants(): void
    {
        $ana = $this->account(self::ANA);
        $luis = $this->account(self::LUIS);
        $this->ledgerSubject(InMemoryActivitySource::TYPE, '42', 'game_session', [$this->recordedFact($ana), $this->recordedFact($luis)]);
        $this->achievement($this->countRule());
        $this->assertSame(2, DB::table('achievements_grants')->count());

        event(new UserAnonymized($ana));

        $this->assertSame(0, DB::table('achievements_grants')->where('user_id', $ana)->count());
        $this->assertSame(1, DB::table('achievements_grants')->where('user_id', $luis)->count());
    }

    public function test_the_database_refuses_to_delete_an_achievement_somebody_holds(): void
    {
        $ana = $this->account(self::ANA);
        $this->ledgerSubject(InMemoryActivitySource::TYPE, '42', 'game_session', [$this->recordedFact($ana)]);
        $achievement = $this->achievement($this->countRule());

        try {
            DB::transaction(static fn () => DB::table('achievements_catalogue')->where('id', $achievement->id)->delete());
            $this->fail('The achievement was deleted with its grant.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('achievements_grants')->count());
            $this->assertNotNull($achievement->fresh());
        }
    }
}
