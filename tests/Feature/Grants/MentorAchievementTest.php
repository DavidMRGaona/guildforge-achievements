<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Grants;

use App\Application\Services\TimezoneServiceInterface;
use App\Domain\Activity\ActivityRole;
use App\Domain\Activity\Events\ActivityRecorded;
use App\Domain\Events\UserAnonymized;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeZone;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementEvaluatorInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\RecordedFact;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * «Mentor» (achievements#24): whoever ran somebody's first game as a player. Decided by the nightly
 * batch alone, once that first game is more than fourteen days old, and granted with the batch's
 * date, never the game's.
 */
final class MentorAchievementTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use BuildsShowcase;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private const string SESSIONS = InMemoryActivitySource::TYPE;

    private const string PEPA = '0195f3a2-7c1e-7d4b-9a8e-2f6c1b3d4e62';

    private const string NOW = '2026-11-23 03:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->withoutStarterCatalogue();
        $this->registerInMemorySource();
        $this->travelTo(self::NOW);
        // Saving an achievement queues its evaluation for everybody; these tests run the batch themselves
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

    public function test_the_nightly_batch_makes_whoever_ran_a_first_game_a_mentor(): void
    {
        $mentor = $this->mentor();
        $this->played('s1', [self::ANA], [self::LUIS], daysAgo: 20);
        // Luis's second game: Marta is not his mentor
        $this->played('s2', [self::MARTA], [self::LUIS], daysAgo: 16);

        $this->nightly();

        $this->assertTrue($this->holds(self::ANA, $mentor));
        $this->assertFalse($this->holds(self::MARTA, $mentor));
        $this->assertFalse($this->holds(self::LUIS, $mentor));
    }

    public function test_it_is_never_granted_when_facts_change_only_by_the_nightly_batch(): void
    {
        $mentor = $this->mentor();
        $people = (string) $this->achievement(['version' => 1, 'metric' => 'people', 'kind' => 'game_session', 'filters' => ['role' => 'director', 'with_role' => 'player'], 'threshold' => 1])->id;
        InMemoryActivitySource::put('s1', [
            $this->activityFact(self::ANA, ActivityRole::Director, $this->daysAgo(20)),
            $this->activityFact(self::LUIS, ActivityRole::Player, $this->daysAgo(20)),
        ]);

        event(new ActivityRecorded(self::SESSIONS, 's1'));
        $this->evaluator()->evaluate([self::ANA, self::LUIS], silent: false);
        $this->evaluator()->evaluateEverybody(null, silent: true);

        // The people she ran games for are counted at once; the mentor waits for the night
        $this->assertTrue($this->holds(self::ANA, $people));
        $this->assertFalse($this->holds(self::ANA, $mentor));

        $this->nightly();

        $this->assertTrue($this->holds(self::ANA, $mentor));
    }

    public function test_a_first_game_is_decided_only_after_more_than_fourteen_days(): void
    {
        $mentor = $this->mentor();
        $this->played('s1', [self::ANA], [self::LUIS], daysAgo: 14);

        $this->nightly();
        $this->assertFalse($this->holds(self::ANA, $mentor));

        $this->travel(1)->day();
        $this->nightly();

        $this->assertTrue($this->holds(self::ANA, $mentor));
    }

    public function test_it_is_granted_with_the_batch_s_date_never_the_game_s(): void
    {
        $mentor = $this->mentor();
        $this->played('s1', [self::ANA], [self::LUIS], daysAgo: 15);

        $this->nightly();

        $grant = DB::table('achievements_grants')->where('achievement_id', $mentor)->sole();
        $this->assertSame(self::NOW, (string) $grant->granted_at);
        $this->assertSame('rule', $grant->source);
    }

    public function test_evaluating_again_grants_nothing_twice(): void
    {
        $mentor = $this->mentor(threshold: 2);
        $this->played('s1', [self::ANA], [self::LUIS], daysAgo: 30);
        $this->played('s2', [self::ANA], [self::MARTA], daysAgo: 20);

        $this->nightly();
        $this->travel(1)->day();
        $this->nightly();
        $this->nightly();

        $this->assertSame(1, DB::table('achievements_grants')->where('achievement_id', $mentor)->count());
    }

    public function test_a_first_table_completed_late_but_within_the_grace_still_decides(): void
    {
        $mentor = $this->mentor();
        // Only Luis's second game is known at first
        $this->played('s2', [self::MARTA], [self::LUIS], daysAgo: 5);
        $this->nightly();

        // His first table is marked completed a few days late
        $this->played('s1', [self::ANA], [self::LUIS], daysAgo: 10);
        $this->travel(6)->days();
        $this->nightly();
        $this->travel(10)->days();
        $this->nightly();

        $this->assertTrue($this->holds(self::ANA, $mentor));
        $this->assertFalse($this->holds(self::MARTA, $mentor));
    }

    public function test_among_several_directors_the_one_who_created_the_table_is_the_mentor(): void
    {
        $mentor = $this->mentor();
        $this->played('s1', [self::ANA, self::MARTA], [self::LUIS], daysAgo: 20, creators: [self::MARTA]);
        // Created by somebody who did not run it: no mentor at all
        $this->played('s2', [self::ANA, self::MARTA], [self::PEPA], daysAgo: 20, creators: [self::LUIS]);

        $this->nightly();

        $this->assertTrue($this->holds(self::MARTA, $mentor));
        $this->assertFalse($this->holds(self::ANA, $mentor));
        $this->assertSame(1, DB::table('achievements_grants')->where('achievement_id', $mentor)->count());
    }

    public function test_an_anonymized_newcomer_makes_nobody_a_mentor(): void
    {
        $mentor = $this->mentor();
        $this->played('s1', [self::ANA], [self::LUIS], daysAgo: 20);
        UserModel::query()->whereKey(self::LUIS)->update(['anonymized_at' => now()]);

        $this->nightly();

        $this->assertFalse($this->holds(self::ANA, $mentor));
    }

    public function test_a_first_table_completed_after_the_grace_never_makes_a_second_mentor(): void
    {
        $mentor = $this->mentor();
        $this->played('s1', [self::ANA], [self::LUIS], daysAgo: 20);
        $this->nightly();
        $this->assertTrue($this->holds(self::ANA, $mentor));

        // An earlier table of Luis's is marked completed weeks late: Luis was already decided
        $this->played('s0', [self::MARTA], [self::LUIS], daysAgo: 30);
        $this->nightly();

        $this->assertFalse($this->holds(self::MARTA, $mentor));
        $this->assertSame(1, DB::table('achievements_grants')->where('achievement_id', $mentor)->count());
    }

    public function test_a_first_game_that_stops_counting_after_the_grant_makes_no_second_mentor(): void
    {
        $mentor = $this->mentor();
        $this->played('s1', [self::ANA], [self::LUIS], daysAgo: 30);
        $this->played('s2', [self::MARTA], [self::LUIS], daysAgo: 20);
        $this->nightly();
        $this->assertTrue($this->holds(self::ANA, $mentor));

        // Ana's table is unpublished: Luis's first game in the ledger is now Marta's
        $this->ledgerSubject(self::SESSIONS, 's1', 'game_session', []);
        $this->nightly();

        $this->assertFalse($this->holds(self::MARTA, $mentor));
        // Ana keeps hers, like any grant
        $this->assertTrue($this->holds(self::ANA, $mentor));
    }

    public function test_a_newcomer_decided_without_a_mentor_stays_without_one(): void
    {
        $mentor = $this->mentor();
        // Two directors and neither created the table: no mentor
        $this->played('s1', [self::ANA, self::MARTA], [self::LUIS], daysAgo: 30);
        $this->nightly();

        $this->ledgerSubject(self::SESSIONS, 's1', 'game_session', []);
        $this->played('s2', [self::PEPA], [self::LUIS], daysAgo: 20);
        $this->nightly();

        $this->assertSame(0, DB::table('achievements_grants')->where('achievement_id', $mentor)->count());
        $this->assertNull(DB::table('achievements_mentor_decisions')->where('newcomer_id', self::LUIS)->value('mentor_id'));
    }

    public function test_the_progress_counts_only_what_the_batch_decided(): void
    {
        $ana = $this->person('Ana');
        $luis = $this->person('Luis');
        $this->mentor(threshold: 2, name: 'Mentor');
        $this->played('s1', [(string) $ana->id], [(string) $luis->id], daysAgo: 20);

        // Past the grace, but the night has not decided yet
        $before = $this->achievementsSection($this->render($ana, $ana))?->props['locked'] ?? [];
        $this->assertSame(0, array_column($before, 'progress', 'name')['Mentor']['value'] ?? null);

        $this->nightly();

        $after = $this->achievementsSection($this->render($ana, $ana))?->props['locked'] ?? [];
        $this->assertSame(1, array_column($after, 'progress', 'name')['Mentor']['value'] ?? null);
    }

    public function test_anonymizing_a_newcomer_erases_the_decision_and_never_decides_them_again(): void
    {
        $mentor = $this->mentor(threshold: 2);
        $this->played('s1', [self::ANA], [self::LUIS], daysAgo: 20);
        $this->nightly();
        $this->assertSame(1, DB::table('achievements_mentor_decisions')->where('newcomer_id', self::LUIS)->count());

        UserModel::query()->whereKey(self::LUIS)->update(['anonymized_at' => now()]);
        event(new UserAnonymized(self::LUIS));
        $this->played('s2', [self::ANA], [self::MARTA], daysAgo: 16);
        $this->nightly();

        $this->assertSame(0, DB::table('achievements_mentor_decisions')->where('newcomer_id', self::LUIS)->count());
        // Luis no longer counts for Ana: Marta alone is not two
        $this->assertFalse($this->holds(self::ANA, $mentor));
    }

    public function test_anonymizing_a_mentor_leaves_the_newcomer_decided_with_nobody(): void
    {
        $mentor = $this->mentor();
        $this->played('s1', [self::ANA], [self::LUIS], daysAgo: 30);
        $this->played('s2', [self::MARTA], [self::LUIS], daysAgo: 20);
        $this->nightly();

        UserModel::query()->whereKey(self::ANA)->update(['anonymized_at' => now()]);
        event(new UserAnonymized(self::ANA));
        $this->ledgerSubject(self::SESSIONS, 's1', 'game_session', [$this->recordedFact(self::LUIS, 'player', $this->daysAgo(30))]);
        $this->nightly();

        $this->assertSame(1, DB::table('achievements_mentor_decisions')->where('newcomer_id', self::LUIS)->count());
        $this->assertNull(DB::table('achievements_mentor_decisions')->where('newcomer_id', self::LUIS)->value('mentor_id'));
        $this->assertFalse($this->holds(self::MARTA, $mentor));
    }

    public function test_only_game_sessions_are_first_games_and_a_campaign_session_goes_to_whoever_created_it(): void
    {
        $mentor = $this->mentor();
        // The campaign as a whole is not a game session: Pepa ran it, but it is not Luis's first game
        $this->ledgerSubject('game-tables.campaign', 'c1', 'campaign', [
            $this->recordedFact(self::PEPA, 'director', $this->daysAgo(40)),
            $this->recordedFact(self::LUIS, 'player', $this->daysAgo(40)),
        ]);
        // One of its sessions: the campaign's director and the table's, the latter created it
        $this->played('s1', [self::PEPA, self::MARTA], [self::LUIS], daysAgo: 20, creators: [self::MARTA]);

        $this->nightly();

        $this->assertTrue($this->holds(self::MARTA, $mentor));
        $this->assertFalse($this->holds(self::PEPA, $mentor));
    }

    public function test_a_director_with_a_hidden_name_is_reported_as_a_player_and_is_never_the_mentor(): void
    {
        $mentor = $this->mentor(threshold: 2);
        // Marta ran it with her name hidden: the source reports her as a player
        $this->played('s1', [self::ANA], [self::LUIS, self::MARTA], daysAgo: 20);

        $this->nightly();

        // Ana is Luis's mentor, and Marta's too, since that is Marta's first fact as a player
        $this->assertTrue($this->holds(self::ANA, $mentor));
        $this->assertFalse($this->holds(self::MARTA, $mentor));
    }

    public function test_a_deactivated_newcomer_still_counts(): void
    {
        $mentor = $this->mentor();
        $this->played('s1', [self::ANA], [self::LUIS], daysAgo: 20);
        UserModel::query()->whereKey(self::LUIS)->delete();

        $this->nightly();

        $this->assertTrue($this->holds(self::ANA, $mentor));
    }

    public function test_a_recent_decision_is_told_to_the_mentor_and_an_old_one_is_not(): void
    {
        $mentor = $this->mentor();
        $this->played('s1', [self::ANA], [self::LUIS], daysAgo: 20);
        // A first game long past (the module enabled late, say): granted without a notice
        $this->played('s2', [self::MARTA], [self::PEPA], daysAgo: 90);

        $this->nightly();

        $this->assertFalse((bool) DB::table('achievements_grants')->where('achievement_id', $mentor)->where('user_id', self::ANA)->value('silent'));
        $this->assertTrue((bool) DB::table('achievements_grants')->where('achievement_id', $mentor)->where('user_id', self::MARTA)->value('silent'));
    }

    public function test_nothing_is_granted_while_community_achievements_are_off(): void
    {
        $mentor = $this->mentor();
        $this->played('s1', [self::ANA], [self::LUIS], daysAgo: 20);
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(community: false));

        $this->nightly();

        $this->assertFalse($this->holds(self::ANA, $mentor));
    }

    public function test_the_batch_runs_every_night_where_people_are(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(static fn ($event): bool => $event->description === 'achievements:mentors');

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertNotNull($event);
        $this->assertSame('0 3 * * *', $event->expression);
        $timezone = $event->timezone;
        $this->assertSame(
            $this->app->make(TimezoneServiceInterface::class)->displayTimezone()->getName(),
            $timezone instanceof DateTimeZone ? $timezone->getName() : $timezone,
        );
    }

    public function test_others_see_it_only_after_the_nightly_photo_by_month_and_never_its_progress(): void
    {
        $ana = $this->person('Ana');
        $luis = $this->person('Luis');
        $marta = $this->person('Marta');
        $mentor = $this->mentor(threshold: 2, name: 'Mentor');
        $this->played('s1', [(string) $ana->id], [(string) $luis->id], daysAgo: 20);
        $this->refreshRarity();

        $this->nightly();
        $this->assertFalse($this->holds((string) $ana->id, $mentor));

        $own = $this->achievementsSection($this->render($ana, $ana))?->props['locked'] ?? [];
        $this->assertSame(['value' => 1, 'threshold' => 2, 'inMonths' => false], array_column($own, 'progress', 'name')['Mentor'] ?? null);
        $this->assertSame([], $this->achievementsSection($this->render($ana, $marta))?->props['locked'] ?? []);

        $this->played('s2', [(string) $ana->id], [(string) $marta->id], daysAgo: 16);
        $this->nightly();
        $this->assertTrue($this->holds((string) $ana->id, $mentor));
        // Not in tonight's photo yet
        $this->assertNotContains('Mentor', array_column($this->achievementsSection($this->render($ana, $luis))?->props['earned'] ?? [], 'name'));

        $this->travelTo('2026-11-24 03:30:00');
        $this->refreshRarity();

        $earned = array_column($this->achievementsSection($this->render($ana, $luis))?->props['earned'] ?? [], null, 'name');
        $this->assertSame('2026-11', $earned['Mentor']['grantedMonth'] ?? null);
    }

    private function mentor(int $threshold = 1, ?string $name = null): string
    {
        return (string) $this->achievement(['version' => 1, 'metric' => 'mentor', 'threshold' => $threshold], $name === null ? [] : ['name' => $name])->id;
    }

    /**
     * @param  list<string>  $directors
     * @param  list<string>  $players
     * @param  list<string>  $creators
     */
    private function played(string $id, array $directors, array $players, int $daysAgo, array $creators = []): void
    {
        $when = $this->daysAgo($daysAgo);

        $this->ledgerSubject(self::SESSIONS, $id, 'game_session', [
            ...array_map(fn (string $user): RecordedFact => $this->recordedFact($user, 'director', $when), $directors),
            ...array_map(fn (string $user): RecordedFact => $this->recordedFact($user, 'player', $when), $players),
            ...array_map(fn (string $user): RecordedFact => $this->recordedFact($user, 'creator', $when), $creators),
        ]);
    }

    private function daysAgo(int $days): string
    {
        return now()->subDays($days)->format('Y-m-d H:i:s');
    }

    /**
     * The job the scheduler dispatches every night, run here.
     */
    private function nightly(): void
    {
        $this->app->forgetScopedInstances();
        $this->app->call([new EvaluateAchievementsJob(silent: false, onlyNightly: true), 'handle']);
    }

    private function holds(string $userId, string $achievementId): bool
    {
        $userId = strtolower($userId);

        return in_array($achievementId, $this->app->make(AchievementGrantRepositoryInterface::class)->heldAchievementIds([$userId])[$userId] ?? [], true);
    }

    private function evaluator(): AchievementEvaluatorInterface
    {
        $this->app->forgetScopedInstances();

        return $this->app->make(AchievementEvaluatorInterface::class);
    }
}
