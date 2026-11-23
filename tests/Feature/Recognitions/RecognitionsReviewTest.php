<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Recognitions;

use App\Application\Activity\DTOs\ActivityFactDTO;
use App\Application\Mail\Services\MailSubjectMuteServiceInterface;
use App\Application\SiteNotifications\Services\SiteNotificationServiceInterface;
use App\Domain\Activity\ActivityRole;
use App\Domain\Events\UserAnonymized;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use DateTimeZone;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Achievements\Application\Enums\GiveRecognitionOutcome;
use Modules\Achievements\Application\Services\AchievementEvaluatorInterface;
use Modules\Achievements\Domain\Recognitions\RecognitionRefusal;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Repositories\RecognitionRepositoryInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Filament\Livewire\AccountRecognitions;
use Modules\Achievements\Filament\Support\RecognitionModerationAccess;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\RecognitionModel;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Notifications\AchievementNoticeCategories;
use Modules\Achievements\Policies\AchievementPolicy;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsNotices;
use Modules\Achievements\Tests\Support\BuildsRecognitions;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Modules\Achievements\Tests\Support\FailingMailSubjects;
use Modules\Achievements\Tests\Support\RecordingSubjectLocks;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * What the review of #11 asked for: random ids, giving under the subject's lock, the same answer
 * for any refusal, the limiter, notices forgotten with their recognition and the evaluator never
 * walking anybody twice.
 */
final class RecognitionsReviewTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use BuildsNotices;
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

        parent::tearDown();
    }

    public function test_the_id_is_random_never_time_ordered(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->give($this->ana, 'mesa-1', $this->bob);

        $id = (string) DB::table('achievements_recognitions')->value('id');

        // The version is the first digit of the third group: 4 is random, 7 would carry the instant
        $this->assertSame('4', $id[14]);
    }

    public function test_giving_reads_the_source_and_stores_under_the_subjects_lock(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $locks = new RecordingSubjectLocks($this->app->make(AchievementFactRepositoryInterface::class));
        $this->app->instance(AchievementFactRepositoryInterface::class, $locks);

        $this->assertSame(GiveRecognitionOutcome::Given, $this->give($this->ana, 'mesa-1', $this->bob));

        $this->assertSame([[InMemoryActivitySource::TYPE, 'mesa-1']], $locks->locked);
        // Nothing stored before the lock was taken, and stored before it was let go
        $this->assertSame([0], $locks->rowsWhenTaken);
        $this->assertSame([1], $locks->rowsWhenReleased);
    }

    public function test_a_giver_the_ledger_has_not_recorded_in_the_game_gives_nothing(): void
    {
        // The ledger knows only Bob; the source already lists Ana too
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->bob);
        InMemoryActivitySource::put('mesa-1', [$this->fact($this->ana), $this->fact($this->bob)]);

        $this->assertSame([], $this->recognitions()->openSubjects($this->ana));
        $this->assertSame(GiveRecognitionOutcome::Refused, $this->give($this->ana, 'mesa-1', $this->bob));
    }

    public function test_a_giver_the_source_no_longer_lists_gives_nothing(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        // Ana was taken off the table, and the ledger has not heard yet
        InMemoryActivitySource::put('mesa-1', [$this->fact($this->bob)]);

        $this->assertSame([], $this->recognitions()->openSubjects($this->ana));
        $this->assertSame(GiveRecognitionOutcome::Refused, $this->give($this->ana, 'mesa-1', $this->bob));
        $this->assertSame(0, $this->recognitionCount());
    }

    public function test_a_member_who_did_not_play_and_an_address_nobody_has_get_the_same_answer(): void
    {
        $marta = $this->person('Marta');
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);

        $answers = [];

        foreach ([$this->handleOf($marta), 'nadie-con-esta-direccion'] as $handle) {
            $response = $this->actingAs($this->ana)->post(route('achievements.recognitions.store'), [
                'sourceType' => InMemoryActivitySource::TYPE,
                'subjectId' => 'mesa-1',
                'receiver' => $handle,
                'type' => $this->typeId(),
            ]);
            $answers[] = [$response->getStatusCode(), $response->headers->get('Location'), session('error'), session('success'), session('info')];
            $this->app['session.store']->flush();
        }

        $this->assertSame($answers[0], $answers[1]);
        $this->assertSame(303, $answers[0][0]);
        $this->assertSame(0, $this->recognitionCount());
    }

    public function test_giving_and_taking_away_are_limited_per_account(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $payload = ['sourceType' => InMemoryActivitySource::TYPE, 'subjectId' => 'mesa-1', 'receiver' => 'nadie', 'type' => $this->typeId()];

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($this->ana)->post(route('achievements.recognitions.store'), $payload)->assertRedirect();
        }

        $this->actingAs($this->ana)->post(route('achievements.recognitions.store'), $payload)->assertTooManyRequests();
        $this->actingAs($this->ana)->delete(route('achievements.recognitions.destroy', ['recognition' => '00000000-0000-4000-8000-000000000000']))->assertTooManyRequests();

        // Somebody else keeps their own allowance
        $this->actingAs($this->bob)->post(route('achievements.recognitions.store'), $payload)->assertRedirect();
    }

    public function test_the_notice_goes_when_the_receiver_takes_it_away(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->give($this->ana, 'mesa-1', $this->bob);
        $this->assertCount(1, $this->bellOf($this->bob, AchievementNoticeCategories::RECOGNITIONS));

        $this->recognitions()->deleteReceived($this->bob, $this->recognitions()->received($this->bob)[0]->id);

        $this->assertSame([], $this->bellOf($this->bob, AchievementNoticeCategories::RECOGNITIONS));
    }

    public function test_the_notice_goes_when_the_ledger_takes_the_recognition_away(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->give($this->ana, 'mesa-1', $this->bob);

        $this->played('mesa-1', '2026-12-08 18:00:00', $this->bob);

        $this->assertSame([], $this->bellOf($this->bob, AchievementNoticeCategories::RECOGNITIONS));
    }

    public function test_the_notice_goes_when_the_giver_is_anonymized(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->give($this->ana, 'mesa-1', $this->bob);

        event(new UserAnonymized((string) $this->ana->id));

        $this->assertSame([], $this->bellOf($this->bob, AchievementNoticeCategories::RECOGNITIONS));
    }

    public function test_the_notice_goes_when_the_moderation_deletes_it(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->give($this->ana, 'mesa-1', $this->bob);
        $this->actingAs($this->userWith('users.view_any', 'users.view', AchievementPolicy::MODERATE_RECOGNITIONS));

        Livewire::test(AccountRecognitions::class, ['userId' => (string) $this->bob->id])
            ->callAction(TestAction::make(AccountRecognitions::DELETE_ACTION)->table(RecognitionModel::query()->sole()), data: ['confirmation' => true])
            ->assertHasNoFormErrors();

        $this->assertSame([], $this->bellOf($this->bob, AchievementNoticeCategories::RECOGNITIONS));
    }

    public function test_walking_everybody_never_counts_one_person_twice(): void
    {
        $this->withoutStarterCatalogue();
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->give($this->ana, 'mesa-1', $this->bob);
        $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        $this->achievement(AchievementRule::recognitions(1)?->toArray() ?? [], ['name' => 'Primer reconocimiento']);
        $this->travelTo(new DateTimeImmutable('2027-01-02 12:00:00', new DateTimeZone('UTC')));
        $this->app->forgetScopedInstances();

        $result = $this->app->make(AchievementEvaluatorInterface::class)->evaluateEverybody(null, silent: true);

        // Ana and Bob, once each (Bob has facts and recognitions)
        $this->assertSame(2, $result->users);
        $this->assertSame(1, DB::table('achievements_grants')->where('user_id', strtolower((string) $this->bob->id))->whereIn('achievement_id', DB::table('achievements_catalogue')->where('name', 'Primer reconocimiento')->pluck('id'))->count());
    }

    public function test_each_recognition_has_its_own_notice_and_taking_one_away_keeps_the_other(): void
    {
        $marta = $this->person('Marta');
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob, $marta);
        $this->give($this->ana, 'mesa-1', $this->bob);
        $this->give($marta, 'mesa-1', $this->bob);

        $notices = $this->bellOf($this->bob, AchievementNoticeCategories::RECOGNITIONS);
        $this->assertCount(2, $notices);
        $this->assertSame([1, 1], array_map(static fn ($notice): int => $notice->count, $notices));

        $fromAna = array_first(array_filter($this->recognitions()->received($this->bob), static fn ($one): bool => $one->personName === 'Ana'));
        $this->assertNotNull($fromAna);
        $this->recognitions()->deleteReceived($this->bob, $fromAna->id);

        $left = $this->bellOf($this->bob, AchievementNoticeCategories::RECOGNITIONS);
        $this->assertCount(1, $left);
        $this->assertSame('Marta te ha reconocido: «Gran narración»', $left[0]->title);
    }

    public function test_the_giver_is_the_notices_actor_so_their_name_goes_with_them(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->give($this->ana, 'mesa-1', $this->bob);

        $actors = json_decode((string) DB::table('site_notifications')->where('category', AchievementNoticeCategories::RECOGNITIONS)->value('actor_ids'), true);
        $this->assertSame([strtolower((string) $this->ana->id)], $actors);

        $this->app->make(SiteNotificationServiceInterface::class)->forgetActor(strtolower((string) $this->ana->id));
        $this->assertSame([], $this->bellOf($this->bob, AchievementNoticeCategories::RECOGNITIONS));
    }

    public function test_a_moderator_who_cannot_manage_an_administrators_account_cannot_delete_there(): void
    {
        $admin = UserModel::factory()->admin()->create(['display_name' => 'Admin']);
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $admin);
        $this->give($this->ana, 'mesa-1', $admin);
        $this->actingAs($this->userWith('users.view_any', 'users.view', AchievementPolicy::MODERATE_RECOGNITIONS));
        $record = RecognitionModel::query()->sole();

        // The table is shown (they may view the account), the deletion is not offered
        Livewire::test(AccountRecognitions::class, ['userId' => (string) $admin->id])
            ->assertTableActionHidden(AccountRecognitions::DELETE_ACTION, $record);
        // And the same rule refuses it on the server, where the action asks again when it runs
        $this->assertTrue(RecognitionModerationAccess::allows((string) $admin->id));
        $this->assertFalse(RecognitionModerationAccess::allowsDeleting((string) $admin->id));
        $this->assertTrue(RecognitionModerationAccess::allowsDeleting((string) $this->ana->id));
        $this->assertSame(1, $this->recognitionCount());
    }

    public function test_an_account_anonymized_meanwhile_is_refused_not_already_given(): void
    {
        $this->bob->forceFill(['anonymized_at' => now()])->save();

        $refusal = $this->app->make(RecognitionRepositoryInterface::class)->give(
            $this->typeId(), (string) $this->ana->id, (string) $this->bob->id, InMemoryActivitySource::TYPE, 'mesa-1', 'game_session', new DateTimeImmutable,
        );

        $this->assertSame(RecognitionRefusal::AccountGone, $refusal);
        $this->assertSame(0, $this->recognitionCount());
    }

    public function test_a_failure_forgetting_a_notice_never_undoes_the_ledgers_deletion(): void
    {
        $this->played('mesa-1', '2026-12-08 18:00:00', $this->ana, $this->bob);
        $this->give($this->ana, 'mesa-1', $this->bob);
        $this->app->instance(MailSubjectMuteServiceInterface::class, new FailingMailSubjects);

        $this->played('mesa-1', '2026-12-08 18:00:00', $this->bob);

        $this->assertSame(0, $this->recognitionCount());
    }

    private function give(UserModel $giver, string $subjectId, UserModel $receiver): GiveRecognitionOutcome
    {
        return $this->recognitions()->give($giver, InMemoryActivitySource::TYPE, $subjectId, $this->handleOf($receiver), $this->typeId());
    }

    private function fact(UserModel $person): ActivityFactDTO
    {
        return new ActivityFactDTO((string) $person->id, ActivityRole::Player, new DateTimeImmutable('2026-12-08 18:00:00', new DateTimeZone('UTC')));
    }
}
