<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Ledger;

use App\Domain\Activity\ActivityRole;
use App\Domain\Activity\Events\ActivityRecorded;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Events\AchievementFactsChanged;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\MetricKinds;
use Modules\Achievements\Listeners\RecordActivityFacts;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\ScriptedActivitySource;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;

final class RecordActivityFactsTest extends ModuleTestCase
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
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();
        ScriptedActivitySource::reset();

        parent::tearDown();
    }

    public function test_the_listener_runs_on_the_queue_after_commit(): void
    {
        $this->assertContains(ShouldQueue::class, class_implements(RecordActivityFacts::class));
        $this->assertTrue($this->app->make(RecordActivityFacts::class)->afterCommit);
    }

    public function test_a_recorded_subject_is_mirrored_from_its_source(): void
    {
        InMemoryActivitySource::put('42', [
            $this->activityFact(self::ANA, ActivityRole::Director, system: 'pf2'),
            $this->activityFact(self::LUIS, system: 'pf2'),
        ]);

        event(new ActivityRecorded(InMemoryActivitySource::TYPE, '42'));

        $facts = $this->ledger()->forUser(self::ANA);
        $this->assertCount(1, $facts);
        $this->assertSame(InMemoryActivitySource::TYPE, $facts[0]->sourceType);
        $this->assertSame('director', $facts[0]->role);
        $this->assertSame(MetricKinds::GAME_SESSION, $facts[0]->kind);
        $this->assertSame('pf2', $facts[0]->attributes['game_system']->id);
        Event::assertDispatched(
            AchievementFactsChanged::class,
            static fn (AchievementFactsChanged $event): bool => $event->userIds === [self::ANA, self::LUIS] && ! $event->silent,
        );
    }

    public function test_the_subject_is_read_and_written_under_its_lock(): void
    {
        InMemoryActivitySource::put('42', [$this->activityFact(self::ANA)]);

        event(new ActivityRecorded(InMemoryActivitySource::TYPE, '42'));
        event(new ActivityRecorded(InMemoryActivitySource::TYPE, '42'));

        // One row per subject, kept for good, even before the subject has any facts
        $this->assertSame(1, DB::table('achievements_subject_locks')
            ->where(['source_type' => InMemoryActivitySource::TYPE, 'subject_id' => '42'])
            ->count());
    }

    public function test_the_people_who_left_are_reported_too(): void
    {
        InMemoryActivitySource::put('42', [$this->activityFact(self::ANA), $this->activityFact(self::LUIS)]);
        event(new ActivityRecorded(InMemoryActivitySource::TYPE, '42'));

        InMemoryActivitySource::put('42', [$this->activityFact(self::MARTA)]);
        event(new ActivityRecorded(InMemoryActivitySource::TYPE, '42'));

        $this->assertSame([self::MARTA], $this->ledger()->userIdsOfSubject(InMemoryActivitySource::TYPE, '42'));
        Event::assertDispatched(
            AchievementFactsChanged::class,
            static fn (AchievementFactsChanged $event): bool => $event->userIds === [self::ANA, self::LUIS, self::MARTA],
        );
    }

    public function test_a_subject_that_no_longer_counts_loses_its_facts(): void
    {
        InMemoryActivitySource::put('42', [$this->activityFact(self::ANA)]);
        event(new ActivityRecorded(InMemoryActivitySource::TYPE, '42'));

        InMemoryActivitySource::put('42', []);
        event(new ActivityRecorded(InMemoryActivitySource::TYPE, '42'));

        $this->assertSame([], $this->ledger()->userIdsOfSubject(InMemoryActivitySource::TYPE, '42'));
    }

    public function test_a_missing_source_changes_nothing(): void
    {
        $this->ledger()->replaceSubject('gone-module.thing', '42', MetricKinds::GAME_SESSION, [$this->recordedFact(self::ANA)]);

        event(new ActivityRecorded('gone-module.thing', '42'));

        $this->assertSame([self::ANA], $this->ledger()->userIdsOfSubject('gone-module.thing', '42'));
        Event::assertNotDispatched(AchievementFactsChanged::class);
    }

    public function test_a_failing_source_changes_nothing(): void
    {
        $this->registerSource(ScriptedActivitySource::class);
        $this->ledger()->replaceSubject(ScriptedActivitySource::TYPE, '7', MetricKinds::TOURNAMENT, [$this->recordedFact(self::ANA, 'organizer')]);
        ScriptedActivitySource::failFacts();

        event(new ActivityRecorded(ScriptedActivitySource::TYPE, '7'));

        $this->assertSame([self::ANA], $this->ledger()->userIdsOfSubject(ScriptedActivitySource::TYPE, '7'));
        Event::assertNotDispatched(AchievementFactsChanged::class);
    }

    public function test_a_failed_read_is_tried_again_later(): void
    {
        $this->registerSource(ScriptedActivitySource::class);
        ScriptedActivitySource::failFacts();
        $listener = $this->app->make(RecordActivityFacts::class)->withFakeQueueInteractions();

        $listener->handle(new ActivityRecorded(ScriptedActivitySource::TYPE, '7'));

        $listener->assertReleased(30);
    }

    public function test_the_last_attempt_of_a_failed_read_gives_up_quietly(): void
    {
        $this->registerSource(ScriptedActivitySource::class);
        $this->ledger()->replaceSubject(ScriptedActivitySource::TYPE, '7', MetricKinds::TOURNAMENT, [$this->recordedFact(self::ANA, 'organizer')]);
        ScriptedActivitySource::failFacts();
        $listener = $this->app->make(RecordActivityFacts::class)->withFakeQueueInteractions();
        $this->assertInstanceOf(FakeJob::class, $listener->job);
        $listener->job->attempts = $listener->tries;

        $listener->handle(new ActivityRecorded(ScriptedActivitySource::TYPE, '7'));

        $listener->assertNotReleased();
        $listener->assertNotFailed();
        $this->assertSame([self::ANA], $this->ledger()->userIdsOfSubject(ScriptedActivitySource::TYPE, '7'));
    }

    public function test_a_missing_source_is_not_tried_again(): void
    {
        $listener = $this->app->make(RecordActivityFacts::class)->withFakeQueueInteractions();

        $listener->handle(new ActivityRecorded('gone-module.thing', '42'));

        $listener->assertNotReleased();
    }

    public function test_facts_of_an_anonymized_account_are_not_kept(): void
    {
        $anonymized = UserModel::factory()->create(['anonymized_at' => now()]);
        InMemoryActivitySource::put('42', [$this->activityFact((string) $anonymized->id), $this->activityFact(self::LUIS)]);

        event(new ActivityRecorded(InMemoryActivitySource::TYPE, '42'));

        $this->assertSame([self::LUIS], $this->ledger()->userIdsOfSubject(InMemoryActivitySource::TYPE, '42'));
    }

    public function test_a_kind_switched_off_is_still_recorded(): void
    {
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(
            metricKinds: [MetricKinds::GAME_SESSION => false],
        ));
        InMemoryActivitySource::put('42', [$this->activityFact(self::ANA)]);

        event(new ActivityRecorded(InMemoryActivitySource::TYPE, '42'));

        $this->assertSame([self::ANA], $this->ledger()->userIdsOfSubject(InMemoryActivitySource::TYPE, '42'));
    }

    public function test_an_id_that_cannot_be_a_subject_is_ignored(): void
    {
        event(new ActivityRecorded(InMemoryActivitySource::TYPE, 'not an id'));

        $this->assertSame(0, DB::table('achievements_facts')->count());
        Event::assertNotDispatched(AchievementFactsChanged::class);
    }

    private function ledger(): AchievementFactRepositoryInterface
    {
        return $this->app->make(AchievementFactRepositoryInterface::class);
    }
}
