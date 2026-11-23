<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Challenges;

use App\Application\Authorization\Services\AssociationMembersAmongInterface;
use App\Application\Services\SettingsServiceInterface;
use App\Domain\Events\UserAnonymized;
use App\Infrastructure\Time\StorageTimezoneResolver;
use DateTimeImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Achievements\Application\Enums\ChallengeEvaluationOutcome;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Domain\Challenges\ChallengeCalculator;
use Modules\Achievements\Domain\Challenges\ChallengeMetric;
use Modules\Achievements\Domain\Challenges\ChallengeRule;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Events\AchievementFactsChanged;
use Modules\Achievements\Domain\Events\AchievementGranted;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Repositories\ChallengeRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\ChallengeModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories\EloquentChallengeRepository;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Jobs\EvaluateChallengesJob;
use Modules\Achievements\Listeners\ForgetAnonymizedAccountContributions;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsChallenges;
use Modules\Achievements\Tests\Support\ClosingAfterSaveRepository;
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

    public function test_a_silent_count_grants_nothing_and_the_next_count_announces(): void
    {
        $ana = $this->account();
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-10 18:00:00')]);
        $challenge = $this->challenge(['target' => 1]);

        $silent = $this->challenges()->evaluate(null, silent: true);

        $this->assertSame([$challenge->id], $silent->completed);
        $this->assertSame(0, $silent->granted);
        $this->assertNotNull($challenge->refresh()->completed_at);
        Event::assertNotDispatched(AchievementGranted::class);

        $this->assertSame(1, $this->challenges()->evaluate(null, silent: false)->granted);
        Event::assertDispatched(AchievementGranted::class, static fn (AchievementGranted $event): bool => ! $event->silent);
    }

    public function test_a_completion_is_announced_when_it_is_found_however_long_ago_it_was_reached(): void
    {
        $ana = $this->account();
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        $this->challenge(['target' => 1]);
        $this->travelTo(new DateTimeImmutable('2027-01-15 12:00:00'));

        $this->challenges()->evaluate(null, silent: false);

        Event::assertDispatched(AchievementGranted::class, static fn (AchievementGranted $event): bool => ! $event->silent);
    }

    public function test_a_count_of_a_version_changed_meanwhile_is_discarded_but_a_new_name_is_not_one(): void
    {
        $ana = $this->account();
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        $model = $this->challenge(['target' => 1]);
        $repository = $this->app->make(ChallengeRepositoryInterface::class);
        $progress = new ChallengeCalculator()->progress(ChallengeRule::make(ChallengeMetric::Subjects, 'game_session', 1) ?? $this->fail('rule'), $this->app->make(AchievementFactRepositoryInterface::class)->forKindBetween('game_session', new DateTimeImmutable('2026-12-01'), new DateTimeImmutable('2027-01-01')));
        $loaded = $repository->find($model->id);
        $this->assertNotNull($loaded);

        // A new name: the count of what was loaded still stands
        $model->update(['name' => 'Otro nombre']);
        $this->assertSame($loaded->countVersion, $model->refresh()->count_version);
        $this->assertNotNull($repository->saveProgress($model->id, $progress, new DateTimeImmutable('2026-12-20 12:00:10'), $loaded->countVersion));

        // Its days changed after the count loaded it: discarded (the change queued a count of its own)
        new UniqueLock($this->app->make(Repository::class))->release(new EvaluateChallengesJob([$model->id], false));
        Bus::fake([EvaluateAchievementsJob::class, EvaluateChallengesJob::class]);
        $model->update(['ends_on' => '2027-01-15']);
        $this->assertSame($loaded->countVersion + 1, $model->refresh()->count_version);
        ChallengeModel::query()->whereKey($model->id)->update(['completed_at' => null]);
        $this->assertNull($repository->saveProgress($model->id, $progress, new DateTimeImmutable('2026-12-20 12:00:20'), $loaded->countVersion));
        $this->assertNull($model->refresh()->completed_at);
        Bus::assertDispatched(EvaluateChallengesJob::class, static fn (EvaluateChallengesJob $job): bool => $job->challengeIds === [$model->id]);
    }

    public function test_once_closed_nobody_else_gets_the_reward(): void
    {
        $ana = $this->account();
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        $challenge = $this->challenge(['target' => 1]);
        $repository = $this->app->make(ChallengeRepositoryInterface::class);

        $this->assertTrue($repository->close($challenge->id, null, new DateTimeImmutable('2026-12-20 12:00:00')));
        $this->assertFalse($repository->close($challenge->id, null, new DateTimeImmutable('2026-12-20 12:00:01')));
        $this->assertFalse($repository->whileOpen($challenge->id, static fn (): null => null));

        $this->assertSame(0, $this->challenges()->evaluate([$challenge->id], silent: false)->granted);
        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_closing_between_the_count_and_the_rewards_grants_nothing(): void
    {
        $ana = $this->account();
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        $challenge = $this->challenge(['target' => 1]);
        // Closed by hand right after the count stored the completion, before any reward
        $this->app->instance(ChallengeRepositoryInterface::class, new ClosingAfterSaveRepository($this->app->make(EloquentChallengeRepository::class)));

        $result = $this->challenges()->evaluate([$challenge->id], silent: false);

        $this->assertSame([$challenge->id], $result->completed);
        $this->assertSame(0, $result->granted);
        $this->assertSame(0, DB::table('achievements_grants')->count());
        $challenge->refresh();
        $this->assertNotNull($challenge->completed_at);
        $this->assertNotNull($challenge->closed_at);
    }

    public function test_an_account_anonymized_while_it_is_counted_adds_no_row(): void
    {
        [$ana, $luis] = [$this->account(), $this->account()];
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00'), $this->recordedFact($luis, when: '2026-12-02 18:00:00')]);
        $challenge = $this->challenge(['target' => 10]);
        $repository = $this->app->make(ChallengeRepositoryInterface::class);
        $loaded = $repository->find($challenge->id);
        $this->assertNotNull($loaded);
        $rule = $loaded->rule;
        $this->assertInstanceOf(ChallengeRule::class, $rule);
        // The facts were read with Ana still there; she is anonymized before the count is stored
        $facts = $this->app->make(AchievementFactRepositoryInterface::class)->forKindBetween('game_session', new DateTimeImmutable('2026-12-01 00:00:00'), new DateTimeImmutable('2026-12-31 23:59:59'));
        $progress = $this->app->make(ChallengeCalculator::class)->progress($rule, $facts);
        $this->assertCount(2, $progress->contributions);
        DB::table('users')->where('id', $ana)->update(['anonymized_at' => '2026-12-20 11:00:00']);

        $saved = $repository->saveProgress($challenge->id, $progress, new DateTimeImmutable('2026-12-20 12:00:00'), $loaded->countVersion);
        $repository->replaceListed($challenge->id, [$ana, $luis], new DateTimeImmutable('2026-12-20 12:00:00'));

        $this->assertNotNull($saved);
        $this->assertSame(1, $saved->contributors);
        $this->assertSame([$luis], DB::table('achievements_challenge_contributors')->pluck('user_id')->all());
        $this->assertSame([$luis], DB::table('achievements_challenge_listed')->pluck('user_id')->all());
    }

    public function test_no_membership_is_remembered_for_an_anonymized_account(): void
    {
        $ana = $this->account();
        $gone = $this->account(anonymized: true);
        $challenge = $this->challenge([], ['audience' => 'members']);

        $this->app->make(ChallengeRepositoryInterface::class)->rememberAudience($challenge->id, [$ana => true, $gone => true], replace: true, at: new DateTimeImmutable('2026-12-20 12:00:00'));

        $this->assertSame([$ana], DB::table('achievements_challenge_audience')->pluck('user_id')->all());
    }

    public function test_an_anonymized_account_never_counts(): void
    {
        $ana = $this->account();
        $gone = $this->account(anonymized: true);
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        // Straight to the table: the ledger itself already drops anonymized accounts
        DB::table('achievements_facts')->insert([
            'id' => (string) Str::uuid7(), 'source_type' => self::SESSIONS, 'subject_id' => 't2', 'user_id' => $gone,
            'role' => 'player', 'kind' => 'game_session', 'occurred_at' => '2026-12-03 18:00:00', 'attributes' => '{}',
            'placement' => null, 'created_at' => '2026-12-03 18:00:00', 'updated_at' => '2026-12-03 18:00:00',
        ]);
        $challenge = $this->challenge();

        $this->challenges()->evaluate(null, silent: false);

        $this->assertSame(1, $challenge->refresh()->progress);
        $this->assertSame([$ana], DB::table('achievements_challenge_contributors')->pluck('user_id')->all());
    }

    public function test_a_reward_that_stopped_being_one_is_not_granted(): void
    {
        $ana = $this->account();
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        $secret = $this->reward(['is_secret' => true]);
        $byRule = $this->achievement($this->countRule('event'));
        $this->challenge(['target' => 1], ['reward_achievement_id' => $secret->id]);
        $this->challenge(['target' => 1], ['reward_achievement_id' => $byRule->id]);

        $result = $this->challenges()->evaluate(null, silent: false);

        $this->assertCount(2, $result->completed);
        $this->assertSame(0, $result->granted);
        Event::assertNotDispatched(AchievementGranted::class);
    }

    public function test_once_its_days_are_over_who_is_a_member_is_frozen(): void
    {
        [$ana, $luis, $marta] = [$this->account(), $this->account(), $this->account()];
        $this->ledgerSubject(self::SESSIONS, 't1', 'game_session', [$this->recordedFact($ana, when: '2026-12-02 18:00:00')]);
        $this->ledgerSubject(self::SESSIONS, 't2', 'game_session', [$this->recordedFact($luis, when: '2026-12-03 18:00:00')]);
        $challenge = $this->challenge(['target' => 10], ['audience' => 'members']);
        $members = new MutableMembers([$ana]);
        $this->app->instance(AssociationMembersAmongInterface::class, $members);
        $this->challenges()->evaluate(null, silent: false);
        $this->assertSame(1, $challenge->refresh()->progress);

        // After the last day: Ana left, Luis joined, Marta (a late fact) is a member now
        $this->travelTo(new DateTimeImmutable('2027-01-05 12:00:00'));
        $members->members = [$luis, $marta];
        $this->ledgerSubject(self::SESSIONS, 't3', 'game_session', [$this->recordedFact($marta, when: '2026-12-30 18:00:00')]);
        $this->challenges()->evaluate(null, silent: false);

        $this->assertSame(2, $challenge->refresh()->progress);
        $this->assertEqualsCanonicalizing([$ana, $marta], DB::table('achievements_challenge_contributors')->pluck('user_id')->all());
        // Only Marta, never seen before, was asked after the last day
        $this->assertSame([[$marta]], $members->askedAfter);
    }

    public function test_the_days_are_the_display_zone_s_with_madrid_storage(): void
    {
        $now = now()->toDateTimeString();
        DB::table('settings')->updateOrInsert(['key' => StorageTimezoneResolver::SETTING_KEY], ['id' => (string) Str::uuid(), 'value' => 'Europe/Madrid', 'created_at' => $now, 'updated_at' => $now]);
        (new StorageTimezoneResolver)->apply($this->app);
        $this->app->make(SettingsServiceInterface::class)->clearCache();
        [$ana, $luis, $marta] = [$this->account(), $this->account(), $this->account()];
        // Stored in Madrid now: the wall times are the display zone's
        $this->ledgerSubject(self::SESSIONS, 'first', 'game_session', [$this->recordedFact($ana, when: '2026-12-01 00:30:00')]);
        $this->ledgerSubject(self::SESSIONS, 'last', 'game_session', [$this->recordedFact($luis, when: '2026-12-31 23:30:00')]);
        $this->ledgerSubject(self::SESSIONS, 'after', 'game_session', [$this->recordedFact($marta, when: '2027-01-01 00:30:00')]);
        $challenge = $this->challenge();

        $this->challenges()->evaluate(null, silent: false);

        $this->assertSame(2, $challenge->refresh()->progress);
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
        $challenge = $this->challenge([], ['audience' => 'members']);
        ChallengeModel::query()->whereKey($challenge->id)->update(['created_by' => $ana, 'closed_by' => $ana]);
        $this->app->instance(AssociationMembersAmongInterface::class, new MutableMembers([$ana, $luis]));
        $this->challenges()->evaluate(null, silent: false);
        $this->app->make(ChallengeRepositoryInterface::class)->replaceListed($challenge->id, [$ana, $luis], new DateTimeImmutable);

        $this->app->make(ForgetAnonymizedAccountContributions::class)->handle(new UserAnonymized($ana));

        $this->assertSame([$luis], DB::table('achievements_challenge_contributors')->pluck('user_id')->all());
        $this->assertSame([$luis], DB::table('achievements_challenge_audience')->pluck('user_id')->all());
        $this->assertSame([$luis], DB::table('achievements_challenge_listed')->pluck('user_id')->all());
        $challenge->refresh();
        $this->assertNull($challenge->created_by);
        $this->assertNull($challenge->closed_by);
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

/**
 * The association members a test says there are, and who it was asked about once the days were over.
 */
final class MutableMembers implements AssociationMembersAmongInterface
{
    /** @var list<list<string>> */
    public array $askedAfter = [];

    /**
     * @param  list<string>  $members
     */
    public function __construct(public array $members) {}

    public function among(array $userIds): array
    {
        if (now()->format('Y') === '2027') {
            $this->askedAfter[] = $userIds;
        }

        return array_fill_keys(array_values(array_intersect($this->members, $userIds)), true);
    }
}
