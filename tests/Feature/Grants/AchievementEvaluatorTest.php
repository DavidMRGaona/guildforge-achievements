<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Grants;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\DTOs\GrantedAchievementDTO;
use Modules\Achievements\Application\Enums\EvaluationOutcome;
use Modules\Achievements\Application\Services\AchievementEvaluatorInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Events\AchievementGranted;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Tests\Support\AccountActivitySource;
use Modules\Achievements\Tests\Support\BrokenTournamentSource;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\ScriptedActivitySource;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;

final class AchievementEvaluatorTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private const string SESSIONS = InMemoryActivitySource::TYPE;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutStarterCatalogue();

        $this->registerInMemorySource();
        ScriptedActivitySource::reset();
        // Saving an achievement queues its evaluation for everybody; these tests call the evaluator themselves
        Bus::fake([EvaluateAchievementsJob::class]);
        Event::fake([AchievementGranted::class]);
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();
        ScriptedActivitySource::reset();

        parent::tearDown();
    }

    public function test_a_rule_met_is_granted_with_its_source_and_silence(): void
    {
        // The facts are of 2026-03-14: met two days ago, so it is announced
        $this->travelTo(new \DateTimeImmutable('2026-03-16 12:00:00'));
        $ana = $this->account(self::ANA);
        $tenGames = $this->achievement($this->countRule(threshold: 2));
        $this->played($ana, 's1', 's2');

        $result = $this->evaluator()->evaluate([$ana], silent: false);

        $this->assertSame(EvaluationOutcome::Evaluated, $result->outcome);
        $this->assertEquals([new GrantedAchievementDTO($ana, $tenGames->id, silent: false)], $result->granted);
        $grant = $this->grants()->forUser($ana)[0];
        $this->assertSame($tenGames->id, $grant->achievementId);
        $this->assertSame(GrantSource::Rule, $grant->source);
        $this->assertNull($grant->season);
        $this->assertNull($grant->actorId);
        $this->assertFalse($grant->silent);
        Event::assertDispatched(
            AchievementGranted::class,
            static fn (AchievementGranted $event): bool => $event->userId === $ana && $event->achievementId === $tenGames->id && ! $event->silent && $event->source === 'rule',
        );
    }

    public function test_a_silent_evaluation_stores_its_grants_as_silent(): void
    {
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule());
        $this->played($ana, 's1');

        $this->evaluator()->evaluate([$ana], silent: true);

        $this->assertTrue($this->grants()->forUser($ana)[0]->silent);
        Event::assertDispatched(AchievementGranted::class, static fn (AchievementGranted $event): bool => $event->silent);
    }

    public function test_a_rule_met_long_ago_is_granted_silently_even_from_a_live_change(): void
    {
        // Met on 2026-03-14 and evaluated for the first time months later (a recalculation that never ran)
        $this->travelTo(new \DateTimeImmutable('2026-11-22 12:00:00'));
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule());
        $this->played($ana, 's1');

        $result = $this->evaluator()->evaluate([$ana], silent: false);

        $this->assertTrue($result->granted[0]->silent);
        $this->assertTrue($this->grants()->forUser($ana)[0]->silent);
        Event::assertDispatched(AchievementGranted::class, static fn (AchievementGranted $event): bool => $event->silent);
    }

    public function test_only_the_fact_that_crosses_the_threshold_decides_the_notice(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-11-22 12:00:00'));
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule(threshold: 2));
        // One old game, then the one that crosses the threshold today
        $this->ledgerSubject(self::SESSIONS, 's1', 'game_session', [$this->recordedFact($ana, when: '2025-01-10 18:00:00')]);
        $this->ledgerSubject(self::SESSIONS, 's2', 'game_session', [$this->recordedFact($ana, when: '2026-11-21 18:00:00')]);

        $this->assertFalse($this->evaluator()->evaluate([$ana], silent: false)->granted[0]->silent);
    }

    public function test_a_rule_not_met_grants_nothing(): void
    {
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule(threshold: 3));
        $this->played($ana, 's1', 's2');

        $this->assertSame([], $this->evaluator()->evaluate([$ana], silent: false)->granted);
        $this->assertSame([], $this->grants()->forUser($ana));
    }

    public function test_evaluating_again_grants_nothing_more(): void
    {
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule());
        $this->played($ana, 's1');

        $this->evaluator()->evaluate([$ana], silent: false);
        $second = $this->evaluator()->evaluate([$ana], silent: false);

        $this->assertSame([], $second->granted);
        $this->assertCount(1, $this->grants()->forUser($ana));
        Event::assertDispatchedTimes(AchievementGranted::class, 1);
    }

    public function test_a_grant_written_meanwhile_by_another_job_is_not_reported_again(): void
    {
        $ana = $this->account(self::ANA);
        $achievement = $this->achievement($this->countRule());
        $this->played($ana, 's1');
        DB::table('achievements_grants')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $ana, 'achievement_id' => $achievement->id, 'season' => null, 'season_key' => '',
            'granted_at' => now(), 'source' => 'rule', 'actor_id' => null, 'silent' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The other job's row: the repository's insert is ignored and nothing is announced
        $this->assertNull($this->grants()->grant(new NewGrant($ana, $achievement->id, GrantSource::Rule, silent: false)));
        $this->assertSame([], $this->evaluator()->evaluate([$ana], silent: false)->granted);
        $this->assertCount(1, $this->grants()->forUser($ana));
        Event::assertNotDispatched(AchievementGranted::class);
    }

    public function test_a_grant_stays_when_the_facts_that_earned_it_are_gone(): void
    {
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule());
        $this->played($ana, 's1');
        $this->evaluator()->evaluate([$ana], silent: false);

        $this->ledgerSubject(self::SESSIONS, 's1', 'game_session', []);
        $this->evaluator()->evaluate([$ana], silent: false);

        $this->assertCount(1, $this->grants()->forUser($ana));
    }

    public function test_only_the_accounts_given_are_evaluated(): void
    {
        $ana = $this->account(self::ANA);
        $luis = $this->account(self::LUIS);
        $this->achievement($this->countRule());
        $this->played($ana, 's1');
        $this->played($luis, 's2');

        $result = $this->evaluator()->evaluate([strtoupper($ana), 'not-a-uuid'], silent: false);

        $this->assertSame(1, $result->users);
        $this->assertCount(1, $this->grants()->forUser($ana));
        $this->assertSame([], $this->grants()->forUser($luis));
    }

    public function test_only_the_achievements_given_are_evaluated_when_asked(): void
    {
        $ana = $this->account(self::ANA);
        $first = $this->achievement($this->countRule());
        $other = $this->achievement($this->countRule());
        $this->played($ana, 's1');

        $this->evaluator()->evaluate([$ana], silent: true, achievementIds: [strtoupper($other->id)]);

        $held = array_map(static fn ($grant): string => $grant->achievementId, $this->grants()->forUser($ana));
        $this->assertSame([$other->id], $held);
        $this->assertNotContains($first->id, $held);
    }

    public function test_a_dry_run_reports_what_it_would_grant_and_writes_nothing(): void
    {
        $ana = $this->account(self::ANA);
        $achievement = $this->achievement($this->countRule());
        $this->played($ana, 's1');

        $result = $this->evaluator()->evaluate([$ana], silent: true, dryRun: true);

        $this->assertEquals([new GrantedAchievementDTO($ana, $achievement->id, silent: true)], $result->granted);
        $this->assertSame([], $this->grants()->forUser($ana));
        Event::assertNotDispatched(AchievementGranted::class);
    }

    public function test_anonymized_and_unknown_accounts_get_nothing(): void
    {
        $anonymized = $this->account(anonymized: true);
        $this->achievement($this->countRule());
        // Facts kept for an account that does not exist (the ledger keeps them, like the core's reader)
        $this->ledgerSubject(self::SESSIONS, 's1', 'game_session', [$this->recordedFact(self::MARTA)]);
        DB::table('achievements_facts')->insert([
            'id' => (string) Str::uuid7(), 'source_type' => self::SESSIONS, 'subject_id' => 's2', 'user_id' => $anonymized, 'role' => 'player',
            'kind' => 'game_session', 'occurred_at' => now(), 'attributes' => '{}', 'placement' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->evaluator()->evaluate([self::MARTA, $anonymized], silent: false);

        $this->assertSame([], $result->granted);
        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_settings_that_cannot_be_read_grant_nothing_and_warn_once(): void
    {
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule());
        $this->played($ana, 's1');
        $this->storeSettings('{"secrets": tr');
        $log = Log::spy();

        $first = $this->evaluator()->evaluate([$ana], silent: false);
        $second = $this->evaluator()->evaluate([$ana], silent: false);

        $this->assertSame(EvaluationOutcome::SettingsUnreadable, $first->outcome);
        $this->assertFalse($second->isEvaluated());
        $this->assertSame([], $this->grants()->forUser($ana));
        $log->shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, 'settings could not be read') && $context['reason'] === 'settings_unreadable',
        );
    }

    public function test_settings_never_saved_evaluate_with_the_defaults(): void
    {
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule());
        $this->played($ana, 's1');

        $this->assertCount(1, $this->evaluator()->evaluate([$ana], silent: false)->granted);
    }

    public function test_an_unreadable_rule_is_skipped_and_warned_once(): void
    {
        $ana = $this->account(self::ANA);
        $broken = $this->achievement('{"version":1,"metric":"count","kind":"game_session","threshold":1,"window":{"type":"season"}}');
        $this->played($ana, 's1');
        $log = Log::spy();

        $this->evaluator()->evaluate([$ana], silent: false);
        $this->evaluator()->evaluate([$ana], silent: false);

        $this->assertSame([], $this->grants()->forUser($ana));
        $log->shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => $context === ['achievement_id' => $broken->id, 'reason' => 'rule_unreadable'],
        );
    }

    public function test_achievements_that_are_not_evaluated_grant_nothing(): void
    {
        $ana = $this->account(self::ANA);
        $this->played($ana, 's1');
        $this->achievement($this->countRule(), ['is_active' => false]);
        $this->achievement(['version' => 1, 'metric' => 'manual']);
        // No source of that kind is offered
        $this->achievement($this->countRule('event'));
        $this->ledgerSubject('event-registrations.event', 'e1', 'event', [$this->recordedFact($ana, 'attendee')]);

        $this->assertSame([], $this->evaluator()->evaluate([$ana], silent: false)->granted);
    }

    public function test_a_kind_switched_off_is_not_evaluated(): void
    {
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule());
        $this->played($ana, 's1');
        $this->settings()->save(new AchievementsSettings(metricKinds: ['game_session' => false]));

        $this->assertSame([], $this->evaluator()->evaluate([$ana], silent: false)->granted);

        $this->settings()->save(new AchievementsSettings(metricKinds: ['game_session' => true]));

        $this->assertCount(1, $this->evaluator()->evaluate([$ana], silent: false)->granted);
    }

    public function test_secret_achievements_are_evaluated_only_while_secrets_are_on(): void
    {
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule(), ['is_secret' => true]);
        $this->played($ana, 's1');
        $this->settings()->save(new AchievementsSettings(secrets: false));

        $this->assertSame([], $this->evaluator()->evaluate([$ana], silent: false)->granted);

        $this->settings()->save(new AchievementsSettings(secrets: true));

        $this->assertCount(1, $this->evaluator()->evaluate([$ana], silent: false)->granted);
    }

    public function test_filters_and_metrics_reach_the_rule(): void
    {
        $ana = $this->account(self::ANA);
        $this->registerSource(ScriptedActivitySource::class);
        $podium = $this->achievement($this->countRule('tournament', extra: ['filters' => ['role' => 'player', 'placement_max' => 3]]));
        $systems = $this->achievement(['version' => 1, 'metric' => 'distinct', 'kind' => 'game_session', 'key' => 'game_system', 'threshold' => 2]);
        $this->ledgerSubject(ScriptedActivitySource::TYPE, 't1', 'tournament', [$this->recordedFact($ana, placement: 5)]);
        $this->ledgerSubject(self::SESSIONS, 's1', 'game_session', [$this->recordedFact($ana, system: 'pf2')]);
        $this->ledgerSubject(self::SESSIONS, 's2', 'game_session', [$this->recordedFact($ana, system: 'pf2')]);

        $this->assertSame([], $this->evaluator()->evaluate([$ana], silent: false)->granted);

        $this->ledgerSubject(ScriptedActivitySource::TYPE, 't2', 'tournament', [$this->recordedFact($ana, placement: 2)]);
        $this->ledgerSubject(self::SESSIONS, 's3', 'game_session', [$this->recordedFact($ana, 'director', system: 'coc')]);

        $granted = array_map(static fn (GrantedAchievementDTO $grant): string => $grant->achievementId, $this->evaluator()->evaluate([$ana], silent: false)->granted);
        sort($granted);
        $expected = [$podium->id, $systems->id];
        sort($expected);
        $this->assertSame($expected, $granted);
    }

    public function test_account_years_are_granted_once_the_anniversary_comes(): void
    {
        $ana = $this->account(self::ANA);
        $this->registerSource(AccountActivitySource::class);
        $this->achievement(['version' => 1, 'metric' => 'years', 'basis' => 'account', 'threshold' => 1]);
        $this->ledgerSubject(AccountActivitySource::TYPE, $ana, 'account', [$this->recordedFact($ana, 'owner', when: '2025-11-23 12:00:00')]);

        $this->travelTo(new \DateTimeImmutable('2026-11-22 12:00:00'));
        $this->assertSame([], $this->evaluator()->evaluateEverybody(null, silent: false, onlyAccountYears: true)->granted);

        $this->travelTo(new \DateTimeImmutable('2026-11-24 12:00:00'));
        $granted = $this->evaluator()->evaluateEverybody(null, silent: false, onlyAccountYears: true)->granted;
        $this->assertCount(1, $granted);
        $this->assertFalse($granted[0]->silent);
    }

    public function test_an_anniversary_older_than_two_days_is_granted_silently(): void
    {
        $ana = $this->account(self::ANA);
        $this->registerSource(AccountActivitySource::class);
        $this->achievement(['version' => 1, 'metric' => 'years', 'basis' => 'account', 'threshold' => 1]);
        $this->ledgerSubject(AccountActivitySource::TYPE, $ana, 'account', [$this->recordedFact($ana, 'owner', when: '2024-05-01 12:00:00')]);

        $granted = $this->evaluator()->evaluateEverybody(null, silent: false, onlyAccountYears: true)->granted;

        $this->assertCount(1, $granted);
        $this->assertTrue($granted[0]->silent);
    }

    public function test_a_kind_whose_source_is_registered_but_unavailable_is_reported_as_pending(): void
    {
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule('tournament'));
        $this->played($ana, 's1');
        // A tournament source wrote facts before and now cannot be built
        $this->ledgerSubject(BrokenTournamentSource::TYPE, 't1', 'tournament', [$this->recordedFact($ana)]);
        $this->registerSource(BrokenTournamentSource::class);

        $result = $this->evaluator()->evaluateEverybody(null, silent: true);

        $this->assertSame(['tournament'], $result->pendingKinds);
        $this->assertSame([], $result->granted);
    }

    public function test_a_kind_nobody_registers_is_not_pending(): void
    {
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule('event'));
        $this->played($ana, 's1');

        $this->assertSame([], $this->evaluator()->evaluateEverybody(null, silent: true)->pendingKinds);
    }

    public function test_only_kinds_keeps_the_achievements_of_those_kinds(): void
    {
        $ana = $this->account(self::ANA);
        $this->registerSource(AccountActivitySource::class);
        $sessions = $this->achievement($this->countRule());
        $this->achievement(['version' => 1, 'metric' => 'years', 'basis' => 'account', 'threshold' => 1]);
        $this->played($ana, 's1');
        $this->ledgerSubject(AccountActivitySource::TYPE, $ana, 'account', [$this->recordedFact($ana, 'owner', when: '2020-01-01 12:00:00')]);

        $granted = $this->evaluator()->evaluateEverybody(null, silent: true, onlyKinds: ['game_session'])->granted;

        $this->assertSame([$sessions->id], array_map(static fn (GrantedAchievementDTO $grant): string => $grant->achievementId, $granted));
    }

    public function test_everybody_with_facts_of_the_kinds_is_evaluated_in_chunks(): void
    {
        $this->achievement($this->countRule());
        $ids = [];

        for ($i = 0; $i < AchievementEvaluatorInterface::USERS_PER_CHUNK + 3; $i++) {
            $ids[] = $this->account();
        }

        $this->ledgerSubject(self::SESSIONS, 'big', 'game_session', array_map(fn (string $id) => $this->recordedFact($id), $ids));

        $result = $this->evaluator()->evaluateEverybody(null, silent: true);

        $this->assertSame(count($ids), $result->users);
        $this->assertCount(count($ids), $result->granted);
        $this->assertSame(count($ids), DB::table('achievements_grants')->where('silent', true)->count());
    }

    public function test_only_account_years_leaves_other_achievements_alone(): void
    {
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule());
        $this->played($ana, 's1');

        $this->assertSame([], $this->evaluator()->evaluateEverybody(null, silent: false, onlyAccountYears: true)->granted);
    }

    private function played(string $userId, string ...$subjects): void
    {
        foreach ($subjects as $subject) {
            $this->ledgerSubject(self::SESSIONS, $subject, 'game_session', [$this->recordedFact($userId)]);
        }
    }

    private function storeSettings(string $value): void
    {
        DB::table('settings')->insert(['id' => (string) Str::uuid(), 'key' => 'module_settings:achievements', 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        $this->app->forgetScopedInstances();
    }

    private function evaluator(): AchievementEvaluatorInterface
    {
        // A new instance per call, as each queued job builds its own
        $this->app->forgetScopedInstances();

        return $this->app->make(AchievementEvaluatorInterface::class);
    }

    private function settings(): AchievementsSettingsInterface
    {
        return $this->app->make(AchievementsSettingsInterface::class);
    }

    private function grants(): AchievementGrantRepositoryInterface
    {
        return $this->app->make(AchievementGrantRepositoryInterface::class);
    }
}
