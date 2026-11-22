<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Grants;

use App\Domain\Activity\Events\ActivityRecorded;
use App\Domain\Events\UserAnonymized;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Achievements\Domain\Events\AchievementFactsChanged;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Listeners\EvaluateAchievementsForChangedFacts;
use Modules\Achievements\Listeners\ForgetAnonymizedAccountGrants;
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

        Bus::assertDispatchedTimes(EvaluateAchievementsJob::class, 2);
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

    public function test_deleting_an_achievement_deletes_its_grants(): void
    {
        $ana = $this->account(self::ANA);
        $this->ledgerSubject(InMemoryActivitySource::TYPE, '42', 'game_session', [$this->recordedFact($ana)]);
        $achievement = $this->achievement($this->countRule());

        $achievement->delete();

        $this->assertSame(0, DB::table('achievements_grants')->count());
    }
}
