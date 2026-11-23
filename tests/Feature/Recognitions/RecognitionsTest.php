<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Recognitions;

use App\Application\Activity\DTOs\ActivityFactDTO;
use App\Application\Activity\Services\ActivityReaderInterface;
use App\Application\Profiles\DTOs\ResolvedProfileDTO;
use App\Application\Profiles\DTOs\ResolvedProfileSectionDTO;
use App\Domain\Activity\ActivityRole;
use App\Domain\Events\UserAnonymized;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Achievements\Application\DTOs\RecognisablePersonDTO;
use Modules\Achievements\Application\DTOs\RecognitionSubjectDTO;
use Modules\Achievements\Application\Enums\GiveRecognitionOutcome;
use Modules\Achievements\Application\Services\ActivityLedgerInterface;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\RecognitionTypeModel;
use Modules\Achievements\Infrastructure\Profiles\RecognitionsProfileSection;
use Modules\Achievements\Infrastructure\Profiles\RecognitionsProfileStat;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsRecognitions;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Modules\Achievements\Tests\Support\FailingFactsReader;
use Modules\Achievements\Tests\Support\ScriptedActivitySource;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Recognitions between people who played together (#11), one test per decision of the user:
 * who is offered (D1), no tournaments (D2), what the receiver sees (D3), monthly totals (D4),
 * following the ledger (D7), what was given (D8) and anonymization.
 */
final class RecognitionsTest extends ModuleTestCase
{
    use BuildsActivityFacts;
    use BuildsRecognitions;
    use BuildsShowcase;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    private UserModel $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        config(['app.display_timezone' => 'Europe/Madrid']);
        $this->registerInMemorySource();
        Bus::fake([EvaluateAchievementsJob::class]);
        $this->switchRecognitions();
        $this->travelTo(new DateTimeImmutable('2026-12-10 12:00:00', new DateTimeZone('UTC')));

        $this->ana = $this->person('Ana');
        $this->bob = $this->person('Bob');
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();
        ScriptedActivitySource::reset();

        parent::tearDown();
    }

    public function test_a_participant_is_offered_the_others_of_the_same_game_and_never_themselves(): void
    {
        $marta = $this->person('Marta');
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->played('mesa-2', '2026-12-08 18:00:00', $marta);

        $subjects = $this->recognitions()->openSubjects($this->ana);

        $this->assertCount(1, $subjects);
        $this->assertSame('Partida mesa-1', $subjects[0]->title);
        $this->assertSame('2026-12-22', $subjects[0]->closesOn);
        $this->assertSame(['Bob'], $this->names($subjects[0]));
        $this->assertSame($this->handleOf($this->bob), $subjects[0]->people[0]->handle);
    }

    public function test_the_picker_leaves_out_hidden_profiles_hidden_achievements_no_public_name_and_deactivated_accounts(): void
    {
        $hiddenProfile = $this->person('Carla', 'hidden');
        $hiddenAchievements = $this->person('Dani');
        $this->choose($hiddenAchievements, hidden: true);
        $nameless = $this->person('Eva');
        $nameless->forceFill(['display_name' => null])->save();
        $deactivated = $this->person('Fede');
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob, $hiddenProfile, $hiddenAchievements, $nameless, $deactivated);
        $deactivated->delete();

        $subjects = $this->recognitions()->openSubjects($this->ana);

        $this->assertSame(['Bob'], $this->names($subjects[0]));

        // The same answer for each of them as for somebody who was never there
        foreach ([$hiddenProfile, $hiddenAchievements] as $person) {
            $this->assertSame(GiveRecognitionOutcome::Refused, $this->give($this->ana, 'mesa-1', $this->handleOf($person)));
        }

        $this->assertSame(GiveRecognitionOutcome::Refused, $this->give($this->ana, 'mesa-1', $this->handleOf($this->person('Marta'))));
        $this->assertSame(0, $this->recognitionCount());
    }

    public function test_a_profile_only_for_association_members_is_not_offered_to_whoever_is_not_one(): void
    {
        $carla = $this->person('Carla', 'association');
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $carla);

        $this->assertSame([], $this->recognitions()->openSubjects($this->ana));
    }

    public function test_one_per_giver_game_and_receiver(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->played('mesa-2', '2026-12-09 18:00:00', $this->ana, $this->bob);

        $this->assertSame(GiveRecognitionOutcome::Given, $this->give($this->ana, 'mesa-1', $this->handleOf($this->bob)));
        $this->assertSame(GiveRecognitionOutcome::AlreadyGiven, $this->give($this->ana, 'mesa-1', $this->handleOf($this->bob), 'good_companion'));
        $this->assertSame(GiveRecognitionOutcome::Given, $this->give($this->ana, 'mesa-2', $this->handleOf($this->bob)));
        $this->assertSame(GiveRecognitionOutcome::Refused, $this->give($this->ana, 'mesa-1', $this->handleOf($this->ana)));
        $this->assertSame(2, $this->recognitionCount());

        $given = $this->recognitions()->openSubjects($this->ana)[1]->people[0];
        $this->assertSame($this->typeId(), $given->givenTypeId);
    }

    public function test_tournaments_are_left_out(): void
    {
        ScriptedActivitySource::reset();
        $this->registerSource(ScriptedActivitySource::class);
        ScriptedActivitySource::putFacts('torneo-1', [
            new ActivityFactDTO((string) $this->ana->id, ActivityRole::Player, new DateTimeImmutable('2026-12-08 18:00:00')),
            new ActivityFactDTO((string) $this->bob->id, ActivityRole::Player, new DateTimeImmutable('2026-12-08 18:00:00')),
        ]);
        $this->app->make(ActivityLedgerInterface::class)->refreshSubject(ScriptedActivitySource::TYPE, 'torneo-1');

        $this->assertSame([], $this->recognitions()->openSubjects($this->ana));
        $this->assertSame(
            GiveRecognitionOutcome::Refused,
            $this->recognitions()->give($this->ana, ScriptedActivitySource::TYPE, 'torneo-1', $this->handleOf($this->bob), $this->typeId()),
        );
        $this->assertSame(0, $this->recognitionCount());
    }

    public function test_the_window_closes_at_the_end_of_its_last_day_in_the_display_zone(): void
    {
        // 1 December at 23:30 in Madrid: the 14 days end on the 15th at midnight in Madrid (23:00 UTC)
        $this->played('mesa-1', '2026-12-01 22:30:00', $this->ana, $this->bob);

        $this->travelTo(new DateTimeImmutable('2026-12-15 22:59:00', new DateTimeZone('UTC')));
        $this->assertSame('2026-12-15', $this->recognitions()->openSubjects($this->ana)[0]->closesOn);

        $this->travelTo(new DateTimeImmutable('2026-12-15 23:00:00', new DateTimeZone('UTC')));
        $this->assertSame([], $this->recognitions()->openSubjects($this->ana));
        $this->assertSame(GiveRecognitionOutcome::Refused, $this->give($this->ana, 'mesa-1', $this->handleOf($this->bob)));
    }

    public function test_a_game_still_to_be_played_cannot_be_recognised(): void
    {
        $this->played('mesa-1', '2026-12-12 18:00:00', $this->ana, $this->bob);

        $this->assertSame([], $this->recognitions()->openSubjects($this->ana));
    }

    public function test_nothing_is_offered_when_the_source_cannot_be_read(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $real = $this->app->make(ActivityReaderInterface::class);
        // Scoped like the core's, so forgetting scoped instances keeps it
        $this->app->scoped(ActivityReaderInterface::class, static fn (): FailingFactsReader => new FailingFactsReader($real));

        $this->assertSame([], $this->recognitions()->openSubjects($this->ana));
        $this->assertSame(GiveRecognitionOutcome::Refused, $this->give($this->ana, 'mesa-1', $this->handleOf($this->bob)));
    }

    public function test_the_source_decides_who_was_there_not_the_ledger(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        // The source no longer lists Bob, and the ledger has not heard yet
        InMemoryActivitySource::put('mesa-1', [new ActivityFactDTO((string) $this->ana->id, ActivityRole::Player, new DateTimeImmutable('2026-12-08 18:00:00'))]);

        $this->assertSame([], $this->recognitions()->openSubjects($this->ana));
        $this->assertSame(GiveRecognitionOutcome::Refused, $this->give($this->ana, 'mesa-1', $this->handleOf($this->bob)));
    }

    public function test_a_switched_off_type_or_recognitions_switched_off_give_nothing(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        DB::table('achievements_recognition_types')->where('id', $this->typeId())->update(['is_active' => false]);

        $this->assertSame(GiveRecognitionOutcome::Refused, $this->give($this->ana, 'mesa-1', $this->handleOf($this->bob)));

        $this->switchRecognitions(false);
        $this->assertSame([], $this->recognitions()->openSubjects($this->ana));
        $this->assertSame(GiveRecognitionOutcome::Refused, $this->give($this->ana, 'mesa-1', $this->handleOf($this->bob), 'good_companion'));
        $this->assertSame(0, $this->recognitionCount());
    }

    public function test_the_receiver_sees_who_gave_it_with_the_month_and_never_the_game(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->give($this->ana, 'mesa-1', $this->handleOf($this->bob));

        $received = $this->recognitions()->received($this->bob);

        $this->assertCount(1, $received);
        $this->assertSame('Ana', $received[0]->personName);
        $this->assertSame('2026-12', $received[0]->month);
        $this->assertSame('Gran narración', $received[0]->type->name);

        $this->actingAs($this->bob)
            ->get(route('achievements.recognitions'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Achievements/Recognitions', false)
                ->where('received.0.personName', 'Ana')
                ->where('received.0.month', '2026-12')
                ->missing('received.0.subjectId')
                ->missing('received.0.title')
                ->has('given', 0));
    }

    public function test_what_was_given_is_seen_by_the_giver_alone(): void
    {
        $marta = $this->person('Marta');
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->give($this->ana, 'mesa-1', $this->handleOf($this->bob));

        $this->assertSame('Bob', $this->recognitions()->given($this->ana)[0]->personName);
        $this->assertSame([], $this->recognitions()->received($this->ana));
        $this->assertSame([], $this->recognitions()->given($this->bob));
        $this->assertSame([], $this->recognitions()->given($marta));
        $this->assertSame([], $this->recognitions()->received($marta));
    }

    public function test_others_see_the_totals_only_from_the_first_of_the_month_and_the_owner_at_once(): void
    {
        $marta = $this->person('Marta');
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->give($this->ana, 'mesa-1', $this->handleOf($this->bob));

        // The owner at once; anybody else, nothing yet
        $this->assertSame([['Gran narración', 1]], $this->totals($this->render($this->bob, $this->bob)));
        $this->assertSame(1, $this->stat($this->render($this->bob, $this->bob)));
        $this->assertNull($this->section($this->render($this->bob, $marta)));
        $this->assertNull($this->stat($this->render($this->bob, $marta)));

        // A minute before the 1st in Madrid, still nothing
        $this->travelTo(new DateTimeImmutable('2026-12-31 22:59:00', new DateTimeZone('UTC')));
        $this->assertNull($this->section($this->render($this->bob, $marta)));

        // The 1st in Madrid: last month's count
        $this->travelTo(new DateTimeImmutable('2026-12-31 23:00:00', new DateTimeZone('UTC')));
        $this->assertSame([['Gran narración', 1]], $this->totals($this->render($this->bob, $marta)));
        $this->assertSame(1, $this->stat($this->render($this->bob, $marta)));
        $section = $this->section($this->render($this->bob, $marta));
        $this->assertInstanceOf(ResolvedProfileSectionDTO::class, $section);
        $this->assertSame(['totals', 'isOwner', 'pageUrl'], array_keys($section->props));
        $this->assertNull($section->props['pageUrl']);
    }

    public function test_hidden_achievements_hide_the_totals_from_everybody_else(): void
    {
        $marta = $this->person('Marta');
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->give($this->ana, 'mesa-1', $this->handleOf($this->bob));
        $this->travelTo(new DateTimeImmutable('2027-01-02 12:00:00', new DateTimeZone('UTC')));
        $this->choose($this->bob, hidden: true);

        $this->assertNull($this->section($this->render($this->bob, $marta)));
        $this->assertNull($this->stat($this->render($this->bob, $marta)));
        $this->assertSame([['Gran narración', 1]], $this->totals($this->render($this->bob, $this->bob)));
    }

    public function test_the_receiver_takes_one_away_and_nobody_else_can(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->give($this->ana, 'mesa-1', $this->handleOf($this->bob));
        $id = $this->recognitions()->received($this->bob)[0]->id;

        $this->actingAs($this->ana)->delete(route('achievements.recognitions.destroy', ['recognition' => $id]))->assertNotFound();
        $this->assertSame(1, $this->recognitionCount());

        $this->actingAs($this->bob)->delete(route('achievements.recognitions.destroy', ['recognition' => $id]))->assertRedirect();
        $this->assertSame(0, $this->recognitionCount());
    }

    public function test_giving_from_the_page_names_the_person_by_their_address(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);

        $this->actingAs($this->ana)
            ->get(route('achievements.recognitions'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('subjects.0.people.0.handle', $this->handleOf($this->bob))
                ->missing('subjects.0.people.0.userId'));

        $this->actingAs($this->ana)->post(route('achievements.recognitions.store'), [
            'sourceType' => InMemoryActivitySource::TYPE,
            'subjectId' => 'mesa-1',
            'receiver' => $this->handleOf($this->bob),
            'type' => $this->typeId(),
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1, $this->recognitionCount());
    }

    public function test_the_pages_answer_404_with_recognitions_off_and_need_an_account(): void
    {
        $this->get(route('achievements.recognitions'))->assertRedirect();

        $this->switchRecognitions(false);
        $this->actingAs($this->ana)->get(route('achievements.recognitions'))->assertNotFound();
    }

    public function test_a_recognition_goes_when_either_person_leaves_the_game(): void
    {
        $marta = $this->person('Marta');
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob, $marta);
        $this->give($this->ana, 'mesa-1', $this->handleOf($this->bob));
        $this->give($marta, 'mesa-1', $this->handleOf($this->ana));
        $this->give($this->bob, 'mesa-1', $this->handleOf($marta));

        // Bob is taken off the table: what he gave and got for it goes, the rest stays
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $marta);

        $this->assertSame(1, $this->recognitionCount());
        $this->assertSame('Marta', $this->recognitions()->received($this->ana)[0]->personName);

        // The table is gone altogether
        $this->played('mesa-1', '2026-12-08 18:00:00');
        $this->assertSame(0, $this->recognitionCount());
    }

    public function test_anonymizing_either_person_deletes_what_they_gave_and_received(): void
    {
        $marta = $this->person('Marta');
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob, $marta);
        $this->give($this->ana, 'mesa-1', $this->handleOf($this->bob));
        $this->give($this->bob, 'mesa-1', $this->handleOf($marta));
        $this->give($marta, 'mesa-1', $this->handleOf($this->ana));

        event(new UserAnonymized((string) $this->bob->id));

        $this->assertSame(1, $this->recognitionCount());
        $this->assertSame([], $this->recognitions()->given($this->ana));
    }

    public function test_an_anonymized_account_is_never_given_one(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $handle = $this->handleOf($this->bob);
        $this->bob->forceFill(['anonymized_at' => now()])->save();

        $this->assertSame(GiveRecognitionOutcome::Refused, $this->give($this->ana, 'mesa-1', $handle));
        $this->assertSame(0, $this->recognitionCount());
    }

    public function test_a_type_in_use_is_not_deleted(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->give($this->ana, 'mesa-1', $this->handleOf($this->bob));
        $manager = UserModel::factory()->admin()->create();
        $type = RecognitionTypeModel::query()->findOrFail($this->typeId());

        $this->assertFalse($manager->can('delete', $type));
        $this->assertTrue($manager->can('delete', RecognitionTypeModel::query()->where('starter_key', 'good_companion')->sole()));
    }

    private function give(UserModel $giver, string $subjectId, string $handle, string $type = 'great_storytelling'): GiveRecognitionOutcome
    {
        return $this->recognitions()->give($giver, InMemoryActivitySource::TYPE, $subjectId, $handle, $this->typeId($type));
    }

    /**
     * @return list<string>
     */
    private function names(RecognitionSubjectDTO $subject): array
    {
        return array_map(static fn (RecognisablePersonDTO $person): string => $person->publicName, $subject->people);
    }

    private function section(ResolvedProfileDTO $profile): ?ResolvedProfileSectionDTO
    {
        foreach ([...$profile->main, ...$profile->aside] as $section) {
            if ($section->key === RecognitionsProfileSection::KEY) {
                return $section;
            }
        }

        return null;
    }

    /**
     * @return list<array{string, int}>
     */
    private function totals(ResolvedProfileDTO $profile): array
    {
        /** @var list<array{type: array{name: string}, count: int}> $totals */
        $totals = $this->section($profile)->props['totals'] ?? [];

        return array_map(static fn (array $total): array => [$total['type']['name'], $total['count']], $totals);
    }

    private function stat(ResolvedProfileDTO $profile): ?int
    {
        foreach ($profile->stats as $stat) {
            if ($stat->key === RecognitionsProfileStat::KEY) {
                return $stat->value;
            }
        }

        return null;
    }
}
