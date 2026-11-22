<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Challenges;

use App\Application\Authorization\Services\AssociationMembersAmongInterface;
use App\Application\Services\SettingsServiceInterface;
use App\Domain\Events\UserAnonymized;
use DateTimeImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Achievements\Application\Enums\ChallengeEvaluationOutcome;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Events\AchievementFactsChanged;
use Modules\Achievements\Domain\Events\AchievementGranted;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\ChallengeModel;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Jobs\EvaluateChallengesJob;
use Modules\Achievements\Listeners\ForgetAnonymizedAccountContributions;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsChallenges;
use RuntimeException;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

final class ChallengeEvaluatorTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use BuildsChallenges;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private const string SESSIONS = InMemoryActivitySource::TYPE;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->withoutStarterCatalogue();
        $this->registerInMemorySource();
        // These tests call the evaluators themselves
        Bus::fake([EvaluateAchievementsJob::class, EvaluateChallengesJob::class]);
        Event::fake([AchievementGranted::class]);
        $this->switchChallenges();
        $this->travelTo(new DateTimeImmutable('2026-12-20 12:00:00'));
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();

        parent::tearDown();
    }

    public function test_it_counts_the_facts_of_its_days_in_the_display_zone(): void
    {
        [$ana, $luis, $marta, $pablo] = [$this->account(), $this->account(), $this->account(), $this->account()];
        // 00:30 of the 1st in Madrid, 23:30 of the 31st in Madrid: both count
        $this->ledgerSubject(self::SESSIONS, 'first', 'game_session', [$this->recordedFact($ana, when: '2026-11-30 23:30:00')]);
        $this->ledgerSubject(self::SESSIONS, 'last', 'game_session', [$this->recordedFact($luis, when: '2026-12-31 22:30:00')]);
        // 23:30 of the 30th of November and 00:30 of the 1st of January in Madrid: neither
        $this->ledgerSubject(self::SESSIONS, 'before', 'game_session', [$this->recordedFact($marta, when: '2026-11-30 22:30:00')]);
        $this->ledgerSubject(self::SESSIONS, 'after', 'game_session', [$this->recordedFact($pablo, when: '2026-12-31 23:30:00')]);
        $challenge = $this->challenge();

        $result = $this->challenges()->evaluate(null, silent: false);

        $this->assertSame(ChallengeEvaluationOutcome::Evaluated, $result->outcome);
        $this->assertSame(1, $result->counted);
        $challenge->refresh();
        $this->assertSame(2, $challenge->progress);
        $this->assertSame(2, $challenge->contributors);
        $this->assertNull($challenge->completed_at);
        $this->assertEqualsCanonicalizing([$ana, $luis], DB::table('achievements_challenge_contributors')->pluck('user_id')->all());
    }

    public function test_once_complete_everybody_who_added_gets_the_reward_announced(): void
    {
        [$ana, $luis] = [$this->account(), $this->account()];
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-10 18:00:00'), $this->recordedFact($luis, when: '2026-12-10 18:00:00')]);
        $this->ledgerSubject(self::SESSIONS, 't2', 'game_session', [$this->recordedFact($ana, when: '2026-12-12 18:00:00')]);
        $challenge = $this->challenge(['target' => 2]);

        $result = $this->challenges()->evaluate(null, silent: false);

        $this->assertSame([$challenge->id], $result->completed);
        $this->assertSame(2, $result->granted);
        $this->assertSame('2026-12-12 18:00:00', $challenge->refresh()->completed_at?->toDateTimeString());
        $grants = DB::table('achievements_grants')->where('achievement_id', $challenge->reward_achievement_id)->get();
        $this->assertCount(2, $grants);
        $this->assertSame([GrantSource::Challenge->value], $grants->pluck('source')->unique()->values()->all());
        Event::assertDispatched(AchievementGranted::class, static fn (AchievementGranted $event): bool => $event->source === GrantSource::Challenge->value && ! $event->silent);
    }

    public function test_a_silent_count_grants_silently(): void
    {
        $ana = $this->account();
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-10 18:00:00')]);
        $this->challenge(['target' => 1]);

        $this->challenges()->evaluate(null, silent: true);

        Event::assertDispatched(AchievementGranted::class, static fn (AchievementGranted $event): bool => $event->silent);
        Event::assertNotDispatched(AchievementGranted::class, static fn (AchievementGranted $event): bool => ! $event->silent);
    }

    public function test_a_challenge_completed_long_ago_grants_silently(): void
    {
        $ana = $this->account();
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        $this->challenge(['target' => 1]);
        $this->travelTo(new DateTimeImmutable('2027-01-15 12:00:00'));

        $this->challenges()->evaluate(null, silent: false);

        Event::assertDispatched(AchievementGranted::class, static fn (AchievementGranted $event): bool => $event->silent);
    }

    public function test_whoever_adds_after_it_is_complete_gets_the_reward_too(): void
    {
        [$ana, $luis] = [$this->account(), $this->account()];
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        $challenge = $this->challenge(['target' => 1]);
        $this->challenges()->evaluate(null, silent: false);

        $this->ledgerSubject(self::SESSIONS, 't2', 'game_session', [$this->recordedFact($luis, when: '2026-12-15 18:00:00')]);
        $result = $this->challenges()->evaluate(null, silent: false);

        $this->assertSame(1, $result->granted);
        $this->assertSame('2026-12-02 18:00:00', $challenge->refresh()->completed_at?->toDateTimeString());
        $this->assertSame(2, DB::table('achievements_grants')->where('achievement_id', $challenge->reward_achievement_id)->count());
    }

    public function test_a_challenge_stays_complete_when_its_total_drops(): void
    {
        $ana = $this->account();
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        $challenge = $this->challenge(['target' => 1]);
        $this->challenges()->evaluate(null, silent: false);

        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', []);
        $this->challenges()->evaluate(null, silent: false);

        $challenge->refresh();
        $this->assertSame(0, $challenge->progress);
        $this->assertNotNull($challenge->completed_at);
        $this->assertSame(0, DB::table('achievements_challenge_contributors')->count());
        $this->assertSame(1, DB::table('achievements_grants')->where('achievement_id', $challenge->reward_achievement_id)->count());
    }

    public function test_a_reward_taken_away_by_hand_is_not_granted_again(): void
    {
        $ana = $this->account();
        $admin = $this->account();
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        $challenge = $this->challenge(['target' => 1]);
        $granter = $this->app->make(AchievementGranterInterface::class);
        $grant = $granter->grant(new NewGrant($ana, $challenge->reward_achievement_id, GrantSource::Manual, true, $admin));
        $this->assertNotNull($grant);
        $granter->revoke($grant->id, $admin, 'Por error');

        $result = $this->challenges()->evaluate(null, silent: false);

        $this->assertSame(0, $result->granted);
        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_only_association_members_count_in_a_challenge_for_members(): void
    {
        [$ana, $luis] = [$this->account(), $this->account()];
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        $this->ledgerSubject(self::SESSIONS, 't2', 'game_session', [$this->recordedFact($luis, when: '2026-12-03 18:00:00')]);
        $challenge = $this->challenge(['target' => 2], ['audience' => 'members']);
        $this->app->instance(AssociationMembersAmongInterface::class, new readonly class($ana) implements AssociationMembersAmongInterface
        {
            public function __construct(private string $member) {}

            public function among(array $userIds): array
            {
                return in_array($this->member, $userIds, true) ? [$this->member => true] : [];
            }
        });

        $result = $this->challenges()->evaluate(null, silent: false);

        $this->assertSame([], $result->completed);
        $this->assertSame(1, $challenge->refresh()->progress);
        $this->assertSame([$ana], DB::table('achievements_challenge_contributors')->pluck('user_id')->all());
    }

    public function test_members_that_cannot_be_read_leave_the_challenge_as_it_was(): void
    {
        $ana = $this->account();
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        $challenge = $this->challenge(['target' => 1], ['audience' => 'members']);
        $this->app->instance(AssociationMembersAmongInterface::class, new class implements AssociationMembersAmongInterface
        {
            public function among(array $userIds): array
            {
                throw new RuntimeException('roles unavailable');
            }
        });

        $result = $this->challenges()->evaluate(null, silent: false);

        $this->assertSame([$challenge->id], $result->retry);
        $this->assertTrue($result->shouldRetry());
        $this->assertNull($challenge->refresh()->progress_at);
        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_nothing_is_counted_with_the_switch_off(): void
    {
        $ana = $this->account();
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        $challenge = $this->challenge(['target' => 1]);
        $this->switchChallenges(false);

        $result = $this->challenges()->evaluate(null, silent: false);

        $this->assertSame(ChallengeEvaluationOutcome::Off, $result->outcome);
        $this->assertFalse($result->shouldRetry());
        $this->assertSame(0, $challenge->refresh()->progress);
        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_unreadable_settings_count_nothing_and_ask_to_try_again(): void
    {
        $this->challenge(['target' => 1]);
        $settings = $this->mock(SettingsServiceInterface::class);
        $settings->shouldReceive('getStored')->andThrow(new RuntimeException('down'));
        $this->app->forgetScopedInstances();

        $result = $this->challenges()->evaluate(null, silent: false);

        $this->assertSame(ChallengeEvaluationOutcome::SettingsUnreadable, $result->outcome);
        $this->assertTrue($result->shouldRetry());
    }

    public function test_closed_upcoming_and_long_ended_challenges_are_not_counted(): void
    {
        $ana = $this->account();
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-10-02 18:00:00')]);
        $closed = $this->challenge(['target' => 1]);
        ChallengeModel::query()->whereKey($closed->id)->update(['closed_at' => '2026-12-05 10:00:00']);
        $this->challenge(['target' => 1], ['starts_on' => '2027-01-01', 'ends_on' => '2027-01-31']);
        // Ended more than LATE_FACTS_DAYS ago
        $this->challenge(['target' => 1], ['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);

        $result = $this->challenges()->evaluate(null, silent: false);

        $this->assertSame(0, $result->counted);
        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_a_kind_without_source_or_switched_off_keeps_what_was_counted(): void
    {
        $challenge = $this->challenge(['kind' => 'venue_booking', 'target' => 1]);
        ChallengeModel::query()->whereKey($challenge->id)->update(['progress' => 7]);

        $result = $this->challenges()->evaluate(null, silent: false);

        $this->assertSame(0, $result->counted);
        $this->assertSame(7, $challenge->refresh()->progress);
    }

    public function test_a_switched_off_reward_is_not_granted(): void
    {
        $ana = $this->account();
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        $reward = $this->reward(['is_active' => false]);
        $challenge = $this->challenge(['target' => 1], ['reward_achievement_id' => $reward->id]);

        $result = $this->challenges()->evaluate(null, silent: false);

        $this->assertSame([$challenge->id], $result->completed);
        $this->assertSame(0, $result->granted);
    }

    public function test_a_change_in_the_ledger_queues_a_count_only_with_the_switch_on(): void
    {
        event(new AchievementFactsChanged([self::ANA], silent: true));
        Bus::assertDispatched(EvaluateChallengesJob::class, static fn (EvaluateChallengesJob $job): bool => $job->challengeIds === null && $job->silent);

        $this->switchChallenges(false);
        Bus::fake([EvaluateChallengesJob::class]);
        event(new AchievementFactsChanged([self::ANA], silent: false));
        Bus::assertNotDispatched(EvaluateChallengesJob::class);
    }

    public function test_saving_a_challenge_queues_its_count(): void
    {
        $challenge = $this->challenge();
        Bus::assertDispatched(EvaluateChallengesJob::class, static fn (EvaluateChallengesJob $job): bool => $job->challengeIds === [$challenge->id]);

        // The first one still waits in the queue as far as its unique lock knows
        new UniqueLock($this->app->make(Repository::class))->release(new EvaluateChallengesJob([$challenge->id], false));
        Bus::fake([EvaluateChallengesJob::class]);
        $challenge->update(['name' => 'Otro nombre']);
        Bus::assertNotDispatched(EvaluateChallengesJob::class);

        $challenge->update(['ends_on' => '2027-01-15']);
        Bus::assertDispatched(EvaluateChallengesJob::class);
    }

    public function test_anonymizing_an_account_forgets_what_it_added(): void
    {
        [$ana, $luis] = [$this->account(), $this->account()];
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00'), $this->recordedFact($luis, when: '2026-12-02 18:00:00')]);
        $this->challenge();
        $this->challenges()->evaluate(null, silent: false);

        $this->app->make(ForgetAnonymizedAccountContributions::class)->handle(new UserAnonymized($ana));

        $this->assertSame([$luis], DB::table('achievements_challenge_contributors')->pluck('user_id')->all());
    }

    public function test_the_job_asks_to_try_again_while_members_cannot_be_read(): void
    {
        $this->challenge(['target' => 1], ['audience' => 'members']);
        $ana = $this->account();
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        $this->app->instance(AssociationMembersAmongInterface::class, new class implements AssociationMembersAmongInterface
        {
            public function among(array $userIds): array
            {
                throw new RuntimeException('roles unavailable');
            }
        });

        $job = new EvaluateChallengesJob(null, false)->withFakeQueueInteractions();
        $job->handle($this->challenges());

        $job->assertReleased(60);
    }
}
