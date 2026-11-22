<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Grants;

use App\Application\Activity\DTOs\ActivitySubjectDTO;
use App\Domain\Activity\ActivityRole;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\DTOs\BulkGrantCountDTO;
use Modules\Achievements\Application\DTOs\BulkGrantRequestDTO;
use Modules\Achievements\Application\Enums\BulkGrantOutcome;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\BulkGrantInterface;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Events\AchievementGranted;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\ParticipationActivitySource;
use PDOException;
use ReflectionClass;
use ReflectionNamedType;
use Tests\Support\Modules\ModuleTestCase;

final class BulkGrantTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private UserModel $actor;

    private AchievementModel $jornadas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutStarterCatalogue();
        ParticipationActivitySource::reset();
        $this->registerSource(ParticipationActivitySource::class);

        $this->actor = UserModel::factory()->create();
        $this->jornadas = $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Jornadas 2027']);
        // Off by default
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(bulkGrants: true));
    }

    protected function tearDown(): void
    {
        ParticipationActivitySource::reset();

        parent::tearDown();
    }

    public function test_the_preview_only_counts_whoever_held_the_role(): void
    {
        $this->jornadasWith(attendees: [self::ANA, self::LUIS, self::MARTA], organizers: [self::ANA]);

        $preview = $this->bulk()->preview($this->request(ActivityRole::Attendee), $this->actor);

        $this->assertSame(BulkGrantOutcome::Ready, $preview->outcome);
        $this->assertSame(3, $preview->people);
        $this->assertSame(3, $preview->toGrant());
        $this->assertSame(1, $this->bulk()->preview($this->request(ActivityRole::Organizer), $this->actor)->people);
        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_the_preview_carries_counts_and_never_who(): void
    {
        // Whoever grants learns how many attended, never who: player and attendee are not public
        foreach ((new ReflectionClass(BulkGrantCountDTO::class))->getProperties() as $property) {
            $type = $property->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertContains($type->getName(), ['int', BulkGrantOutcome::class], $property->getName());
        }
    }

    public function test_the_preview_counts_apart_who_already_holds_it_and_who_had_it_taken_away(): void
    {
        $this->jornadasWith(attendees: [self::ANA, self::LUIS, self::MARTA]);
        $granter = $this->app->make(AchievementGranterInterface::class);
        $granter->grant(new NewGrant(self::ANA, $this->jornadas->id, GrantSource::Manual, silent: true));
        $luis = $granter->grant(new NewGrant(self::LUIS, $this->jornadas->id, GrantSource::Manual, silent: true));
        $this->assertInstanceOf(AchievementGrant::class, $luis);
        $granter->revoke($luis->id, (string) $this->actor->id, 'No vino');

        $preview = $this->bulk()->preview($this->request(ActivityRole::Attendee), $this->actor);

        $this->assertSame(3, $preview->people);
        $this->assertSame(1, $preview->alreadyHeld);
        $this->assertSame(1, $preview->revoked);
        $this->assertSame(1, $preview->toGrant());
    }

    public function test_a_silent_bulk_grant_gives_it_to_each_attendee_without_a_notice(): void
    {
        Event::fake([AchievementGranted::class]);
        $this->jornadasWith(attendees: [self::ANA, self::LUIS], organizers: [self::MARTA]);

        $result = $this->bulk()->grant($this->request(ActivityRole::Attendee), silent: true, reason: 'Vinieron a las jornadas', actor: $this->actor);

        $this->assertSame(BulkGrantOutcome::Granted, $result->outcome);
        $this->assertSame(2, $result->granted);
        $this->assertEqualsCanonicalizing([self::ANA, self::LUIS], DB::table('achievements_grants')->pluck('user_id')->all());
        $this->assertSame(['bulk'], DB::table('achievements_grants')->pluck('source')->unique()->values()->all());
        $this->assertSame([(string) $this->actor->id], DB::table('achievements_grants')->pluck('actor_id')->unique()->values()->all());
        Event::assertDispatchedTimes(AchievementGranted::class, 2);
        Event::assertNotDispatched(AchievementGranted::class, static fn (AchievementGranted $event): bool => ! $event->silent);
        $log = DB::table('achievements_grant_log')->get();
        $this->assertCount(2, $log);
        $this->assertSame(['bulk'], $log->pluck('source')->unique()->values()->all());
        $this->assertSame(['Vinieron a las jornadas'], $log->pluck('reason')->unique()->values()->all());
    }

    public function test_a_bulk_grant_with_a_notice_tells_each_person(): void
    {
        Event::fake([AchievementGranted::class]);
        $this->jornadasWith(attendees: [self::ANA, self::LUIS]);

        $result = $this->bulk()->grant($this->request(ActivityRole::Attendee), silent: false, reason: null, actor: $this->actor);

        $this->assertSame(2, $result->granted);
        Event::assertDispatchedTimes(AchievementGranted::class, 2);
        Event::assertNotDispatched(AchievementGranted::class, static fn (AchievementGranted $event): bool => $event->silent);
    }

    public function test_a_bulk_grant_skips_who_already_holds_it_and_who_had_it_taken_away(): void
    {
        $this->jornadasWith(attendees: [self::ANA, self::LUIS, self::MARTA]);
        $granter = $this->app->make(AchievementGranterInterface::class);
        $granter->grant(new NewGrant(self::ANA, $this->jornadas->id, GrantSource::Manual, silent: true));
        $luis = $granter->grant(new NewGrant(self::LUIS, $this->jornadas->id, GrantSource::Manual, silent: true));
        $this->assertInstanceOf(AchievementGrant::class, $luis);
        $granter->revoke($luis->id, (string) $this->actor->id, 'No vino');

        $result = $this->bulk()->grant($this->request(ActivityRole::Attendee), silent: true, reason: null, actor: $this->actor);

        $this->assertSame(1, $result->granted);
        $this->assertSame(1, $result->alreadyHeld);
        $this->assertSame(1, $result->revoked);
        $this->assertEqualsCanonicalizing([self::ANA, self::MARTA], DB::table('achievements_grants')->pluck('user_id')->all());
    }

    public function test_nothing_is_granted_while_bulk_grants_are_off(): void
    {
        $this->jornadasWith(attendees: [self::ANA]);
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings);

        $this->assertSame(BulkGrantOutcome::Disabled, $this->bulk()->preview($this->request(ActivityRole::Attendee), $this->actor)->outcome);
        $result = $this->bulk()->grant($this->request(ActivityRole::Attendee), silent: true, reason: null, actor: $this->actor);

        $this->assertSame(BulkGrantOutcome::Disabled, $result->outcome);
        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_nothing_is_granted_when_the_settings_cannot_be_read(): void
    {
        $this->jornadasWith(attendees: [self::ANA]);
        $this->mock(AchievementsSettingsInterface::class)->shouldReceive('readStrictly')->andReturnNull();

        $result = $this->bulk()->grant($this->request(ActivityRole::Attendee), silent: true, reason: null, actor: $this->actor);

        $this->assertSame(BulkGrantOutcome::SettingsUnreadable, $result->outcome);
        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_a_subject_the_viewer_may_not_see_is_never_granted_from(): void
    {
        $this->jornadasWith(attendees: [self::ANA], hidden: true);

        $this->assertSame(BulkGrantOutcome::SubjectNotVisible, $this->bulk()->preview($this->request(ActivityRole::Attendee), $this->actor)->outcome);
        $result = $this->bulk()->grant($this->request(ActivityRole::Attendee), silent: true, reason: null, actor: $this->actor);

        $this->assertSame(BulkGrantOutcome::SubjectNotVisible, $result->outcome);
        $this->assertSame(0, $result->people);
        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_a_source_that_cannot_describe_the_subject_grants_nothing(): void
    {
        $this->jornadasWith(attendees: [self::ANA]);
        ParticipationActivitySource::failDescriptions();

        $result = $this->bulk()->grant($this->request(ActivityRole::Attendee), silent: true, reason: null, actor: $this->actor);

        $this->assertSame(BulkGrantOutcome::SubjectNotVisible, $result->outcome);
        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_facts_that_cannot_be_read_grant_nothing(): void
    {
        $this->jornadasWith(attendees: [self::ANA]);
        ParticipationActivitySource::failFacts();

        $result = $this->bulk()->grant($this->request(ActivityRole::Attendee), silent: true, reason: null, actor: $this->actor);

        $this->assertSame(BulkGrantOutcome::SourceUnavailable, $result->outcome);
        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_an_unknown_source_grants_nothing(): void
    {
        $result = $this->bulk()->grant(
            new BulkGrantRequestDTO('achievements.nobody', '1', ActivityRole::Attendee, $this->jornadas->id),
            silent: true,
            reason: null,
            actor: $this->actor,
        );

        $this->assertSame(BulkGrantOutcome::SubjectNotVisible, $result->outcome);
    }

    public function test_a_switched_off_or_missing_achievement_is_never_granted_in_bulk(): void
    {
        $this->jornadasWith(attendees: [self::ANA]);
        $this->jornadas->forceFill(['is_active' => false])->save();

        $this->assertSame(BulkGrantOutcome::AchievementUnavailable, $this->bulk()->grant($this->request(ActivityRole::Attendee), silent: true, reason: null, actor: $this->actor)->outcome);
        $this->assertSame(
            BulkGrantOutcome::AchievementUnavailable,
            $this->bulk()->preview(new BulkGrantRequestDTO(ParticipationActivitySource::TYPE, '7', ActivityRole::Attendee, '0195f3a2-7c1e-7d4b-9a8e-000000000000'), $this->actor)->outcome,
        );
        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_more_people_than_the_cap_grants_nothing(): void
    {
        $facts = [];

        for ($i = 0; $i <= BulkGrantInterface::MAX_PEOPLE; $i++) {
            $facts[] = $this->activityFact((string) Str::uuid7(), ActivityRole::Attendee);
        }

        ParticipationActivitySource::put('7', 'Jornadas 2027', $facts);
        $this->ledgerSubject(ParticipationActivitySource::TYPE, '7', 'event', [$this->recordedFact((string) Str::uuid7(), 'attendee')]);

        $preview = $this->bulk()->preview($this->request(ActivityRole::Attendee), $this->actor);
        $result = $this->bulk()->grant($this->request(ActivityRole::Attendee), silent: true, reason: null, actor: $this->actor);

        $this->assertSame(BulkGrantOutcome::TooMany, $preview->outcome);
        $this->assertSame(BulkGrantInterface::MAX_PEOPLE + 1, $preview->people);
        $this->assertSame(BulkGrantOutcome::TooMany, $result->outcome);
        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_a_batch_that_fails_keeps_what_earlier_batches_granted(): void
    {
        $people = [];

        for ($i = 0; $i < BulkGrantInterface::PER_BATCH + 20; $i++) {
            $people[] = $this->account();
        }

        ParticipationActivitySource::put('7', 'Jornadas 2027', array_map(fn (string $id) => $this->activityFact($id, ActivityRole::Attendee), $people));
        $this->ledgerSubject(ParticipationActivitySource::TYPE, '7', 'event', [$this->recordedFact($people[0], 'attendee')]);
        $real = $this->app->make(AchievementGranterInterface::class);
        $this->app->instance(AchievementGranterInterface::class, new class($real, BulkGrantInterface::PER_BATCH + 5) implements AchievementGranterInterface
        {
            private int $calls = 0;

            public function __construct(private readonly AchievementGranterInterface $real, private readonly int $failAt) {}

            public function grant(NewGrant $grant): ?AchievementGrant
            {
                if (++$this->calls === $this->failAt) {
                    throw new QueryException('pgsql', 'insert', [], new PDOException('deadlock detected'));
                }

                return $this->real->grant($grant);
            }

            public function revoke(string $grantId, string $actorId, string $reason): ?AchievementGrant
            {
                return $this->real->revoke($grantId, $actorId, $reason);
            }
        });

        $result = $this->bulk()->grant($this->request(ActivityRole::Attendee), silent: true, reason: null, actor: $this->actor);

        $this->assertSame(BulkGrantOutcome::Partial, $result->outcome);
        $this->assertSame(BulkGrantInterface::PER_BATCH, $result->granted);
        // The first batch stays, the failing one is rolled back whole
        $this->assertSame(BulkGrantInterface::PER_BATCH, DB::table('achievements_grants')->count());
        $this->assertSame(BulkGrantInterface::PER_BATCH, DB::table('achievements_grant_log')->count());

        // Granting again finishes the job and skips whoever already holds it
        $this->app->forgetInstance(AchievementGranterInterface::class);
        $again = $this->bulk()->grant($this->request(ActivityRole::Attendee), silent: true, reason: null, actor: $this->actor);
        $this->assertSame(BulkGrantOutcome::Granted, $again->outcome);
        $this->assertSame(20, $again->granted);
        $this->assertSame(BulkGrantInterface::PER_BATCH, $again->alreadyHeld);
    }

    public function test_the_subjects_offered_are_those_the_viewer_may_see_and_match_the_search(): void
    {
        $this->jornadasWith(attendees: [self::ANA]);
        $this->subject('8', 'Torneo de primavera', [self::LUIS]);
        $this->subject('9', 'Reunión de la junta', [self::LUIS], hidden: true);

        $titles = static fn (array $subjects): array => array_map(static fn (ActivitySubjectDTO $subject): string => $subject->title, $subjects);

        $this->assertEqualsCanonicalizing(['Jornadas 2027', 'Torneo de primavera'], $titles($this->bulk()->subjects(ParticipationActivitySource::TYPE, null, $this->actor)));
        $this->assertSame(['Torneo de primavera'], $titles($this->bulk()->subjects(ParticipationActivitySource::TYPE, 'PRIMAV', $this->actor)));
        $this->assertSame([], $this->bulk()->subjects(ParticipationActivitySource::TYPE, 'junta', $this->actor));
        $this->assertSame([], $this->bulk()->subjects('achievements.nobody', null, $this->actor));
    }

    /**
     * @param  list<string>  $attendees
     * @param  list<string>  $organizers
     */
    private function jornadasWith(array $attendees, array $organizers = [], bool $hidden = false): void
    {
        $facts = [];

        foreach ($attendees as $id) {
            $this->account($id);
            $facts[] = $this->activityFact($id, ActivityRole::Attendee);
        }

        foreach ($organizers as $id) {
            if (! in_array($id, $attendees, true)) {
                $this->account($id);
            }

            $facts[] = $this->activityFact($id, ActivityRole::Organizer);
        }

        ParticipationActivitySource::put('7', 'Jornadas 2027', $facts, $hidden);
        $this->ledgerSubject(ParticipationActivitySource::TYPE, '7', 'event', array_map(
            fn ($fact) => $this->recordedFact($fact->userId, $fact->role->value),
            $facts,
        ));
    }

    /**
     * @param  list<string>  $attendees
     */
    private function subject(string $id, string $title, array $attendees, bool $hidden = false): void
    {
        $facts = [];

        foreach ($attendees as $userId) {
            if (UserModel::query()->whereKey($userId)->doesntExist()) {
                $this->account($userId);
            }

            $facts[] = $this->activityFact($userId, ActivityRole::Attendee);
        }

        ParticipationActivitySource::put($id, $title, $facts, $hidden);
        $this->ledgerSubject(ParticipationActivitySource::TYPE, $id, 'event', array_map(
            fn ($fact) => $this->recordedFact($fact->userId, 'attendee'),
            $facts,
        ));
    }

    private function request(ActivityRole $role): BulkGrantRequestDTO
    {
        return new BulkGrantRequestDTO(ParticipationActivitySource::TYPE, '7', $role, $this->jornadas->id);
    }

    private function bulk(): BulkGrantInterface
    {
        return $this->app->make(BulkGrantInterface::class);
    }
}
