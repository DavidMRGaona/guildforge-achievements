<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Grants;

use App\Domain\Activity\ActivityRole;
use App\Domain\Activity\Events\ActivityRecorded;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Enums\AchievementStatus;
use Modules\Achievements\Application\Services\AchievementEvaluatorInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\AchievementStatusRule;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\AchievementPreferences;
use Modules\Achievements\Domain\ValueObjects\RecordedFact;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\FailingCommunityLedger;
use RuntimeException;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;

final class CommunityAchievementsTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private const string SESSIONS = InMemoryActivitySource::TYPE;

    private const string PEPA = '0195f3a2-7c1e-7d4b-9a8e-2f6c1b3d4e62';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutStarterCatalogue();
        $this->registerInMemorySource();
        // Saving an achievement queues its evaluation for everybody; these tests evaluate themselves
        Bus::fake([EvaluateAchievementsJob::class]);

        foreach ([self::ANA, self::LUIS, self::MARTA, self::PEPA] as $id) {
            $this->account($id);
        }
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();

        parent::tearDown();
    }

    public function test_playing_with_enough_different_people_grants_it(): void
    {
        $withThree = $this->achievement($this->peopleRule(3, 'player', 'player'));
        $this->played('s1', [self::MARTA], [self::LUIS, self::ANA]);
        $this->played('s2', [self::MARTA], [self::LUIS, self::ANA]);

        $this->evaluator()->evaluate([self::LUIS], silent: true);
        // Only Ana played with Luis twice: still one person
        $this->assertFalse($this->holds(self::LUIS, $withThree->id));

        $this->played('s3', [self::ANA], [self::LUIS, self::PEPA, self::MARTA]);
        $this->evaluator()->evaluate([self::LUIS], silent: true);

        $this->assertTrue($this->holds(self::LUIS, $withThree->id));
    }

    public function test_running_games_for_different_people_grants_it_to_the_director_only(): void
    {
        $ranForTwo = $this->achievement($this->peopleRule(2, 'director', 'player'));
        $this->played('s1', [self::ANA], [self::LUIS, self::MARTA]);

        $this->evaluator()->evaluate([self::ANA, self::LUIS, self::MARTA], silent: true);

        $this->assertTrue($this->holds(self::ANA, $ranForTwo->id));
        $this->assertFalse($this->holds(self::LUIS, $ranForTwo->id));
    }

    public function test_running_somebody_s_first_game_makes_a_mentor(): void
    {
        $mentor = $this->achievement(['version' => 1, 'metric' => 'mentor', 'threshold' => 1]);
        $this->played('s1', [self::ANA], [self::LUIS], '2026-01-10 18:00:00');
        $this->played('s2', [self::MARTA], [self::LUIS], '2026-02-10 18:00:00');

        $this->evaluator()->evaluate([self::ANA, self::MARTA], silent: true);

        $this->assertTrue($this->holds(self::ANA, $mentor->id));
        $this->assertFalse($this->holds(self::MARTA, $mentor->id));
    }

    public function test_a_change_to_a_newcomer_s_games_reaches_whoever_ran_their_first_one(): void
    {
        $mentor = $this->achievement(['version' => 1, 'metric' => 'mentor', 'threshold' => 1]);
        $this->played('s1', [self::ANA], [self::LUIS], '2026-01-10 18:00:00');

        // Only Luis changed (an older game of his was recorded, say): Ana is evaluated all the same
        $this->evaluator()->evaluate([self::LUIS], silent: true);

        $this->assertTrue($this->holds(self::ANA, $mentor->id));
    }

    public function test_it_grows_from_a_recorded_subject_through_the_pipeline(): void
    {
        $ranForTwo = $this->achievement($this->peopleRule(2, 'director', 'player'));
        InMemoryActivitySource::put('42', [
            $this->activityFact(self::ANA, ActivityRole::Director),
            $this->activityFact(self::LUIS),
            $this->activityFact(self::MARTA),
        ]);

        event(new ActivityRecorded(InMemoryActivitySource::TYPE, '42'));

        $this->assertTrue($this->holds(self::ANA, $ranForTwo->id));
    }

    public function test_people_who_hide_their_achievements_still_count(): void
    {
        $withTwo = $this->achievement($this->peopleRule(2, 'player', 'player'));
        $this->app->make(AchievementPreferencesRepositoryInterface::class)->save(AchievementPreferences::none(self::ANA)->with(hidden: true, featured: []));
        $this->played('s1', [self::MARTA], [self::LUIS, self::ANA, self::PEPA]);

        $this->evaluator()->evaluate([self::LUIS], silent: true);

        $this->assertTrue($this->holds(self::LUIS, $withTwo->id));
    }

    public function test_anonymized_people_never_count(): void
    {
        $withTwo = $this->achievement($this->peopleRule(2, 'player', 'player'));
        $this->played('s1', [self::MARTA], [self::LUIS, self::ANA, self::PEPA]);
        UserModel::query()->whereKey(self::PEPA)->update(['anonymized_at' => now()]);

        $this->evaluator()->evaluate([self::LUIS], silent: true);

        $this->assertFalse($this->holds(self::LUIS, $withTwo->id));
    }

    public function test_a_goal_reached_stays_when_the_count_goes_down(): void
    {
        $withTwo = $this->achievement($this->peopleRule(2, 'player', 'player'));
        $this->played('s1', [self::MARTA], [self::LUIS, self::ANA, self::PEPA]);
        $this->evaluator()->evaluate([self::LUIS], silent: true);
        $this->assertTrue($this->holds(self::LUIS, $withTwo->id));

        // The table stops counting (made private, say): the ledger forgets it
        $this->ledgerSubject(self::SESSIONS, 's1', 'game_session', []);
        $this->evaluator()->evaluate([self::LUIS], silent: true);

        $this->assertTrue($this->holds(self::LUIS, $withTwo->id));
    }

    public function test_switched_off_they_are_not_evaluated_and_say_why(): void
    {
        $withTwo = $this->achievement($this->peopleRule(2, 'player', 'player'));
        $count = $this->achievement($this->countRule());
        $this->played('s1', [self::MARTA], [self::LUIS, self::ANA, self::PEPA]);
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(community: false));
        $this->app->forgetScopedInstances();

        $this->evaluator()->evaluate([self::LUIS], silent: true);

        $this->assertFalse($this->holds(self::LUIS, $withTwo->id));
        $this->assertTrue($this->holds(self::LUIS, $count->id));
        $entity = $this->app->make(AchievementRepositoryInterface::class)->find($withTwo->id);
        $this->assertNotNull($entity);
        $this->assertSame(
            AchievementStatus::CommunityOff,
            $this->app->make(AchievementStatusRule::class)->status($entity, new AchievementsSettings(community: false), $this->app->make(ActivityMetricsInterface::class)->kindsWithSource()),
        );
    }

    public function test_a_dry_run_counts_without_granting(): void
    {
        $withTwo = $this->achievement($this->peopleRule(2, 'player', 'player'));
        $this->played('s1', [self::MARTA], [self::LUIS, self::ANA, self::PEPA]);

        $result = $this->evaluator()->evaluate([self::LUIS], silent: true, dryRun: true);

        $this->assertCount(1, $result->granted);
        $this->assertSame($withTwo->id, $result->granted[0]->achievementId);
        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_a_ledger_that_cannot_be_read_grants_nothing_and_fails_so_the_job_runs_again(): void
    {
        $this->achievement($this->peopleRule(1, 'player', 'player'));
        $this->played('s1', [self::MARTA], [self::LUIS, self::ANA]);
        $this->app->instance(AchievementFactRepositoryInterface::class, new FailingCommunityLedger($this->app->make(AchievementFactRepositoryInterface::class)));

        try {
            $this->evaluator()->evaluate([self::LUIS], silent: true);
            $this->fail('The evaluation should have failed.');
        } catch (RuntimeException) {
            // Expected: the listener or job is retried
        }

        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function peopleRule(int $threshold, ?string $role, ?string $withRole): array
    {
        $filters = array_filter(['role' => $role, 'with_role' => $withRole]);

        return ['version' => 1, 'metric' => 'people', 'kind' => 'game_session', 'threshold' => $threshold] + ($filters === [] ? [] : ['filters' => $filters]);
    }

    /**
     * @param  list<string>  $directors
     * @param  list<string>  $players
     */
    private function played(string $id, array $directors, array $players, string $when = '2026-03-14 18:00:00'): void
    {
        $this->ledgerSubject(self::SESSIONS, $id, 'game_session', [
            ...array_map(fn (string $user): RecordedFact => $this->recordedFact($user, 'director', $when), $directors),
            ...array_map(fn (string $user): RecordedFact => $this->recordedFact($user, 'player', $when), $players),
        ]);
    }

    private function holds(string $userId, string $achievementId): bool
    {
        return in_array($achievementId, $this->app->make(AchievementGrantRepositoryInterface::class)->heldAchievementIds([$userId])[$userId] ?? [], true);
    }

    private function evaluator(): AchievementEvaluatorInterface
    {
        $this->app->forgetScopedInstances();

        return $this->app->make(AchievementEvaluatorInterface::class);
    }
}
