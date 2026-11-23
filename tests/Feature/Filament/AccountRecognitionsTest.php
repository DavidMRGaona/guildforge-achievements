<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Filament;

use App\Domain\Impersonation\Enums\ImpersonationMode;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Infrastructure\Impersonation\ImpersonationContext;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use DateTimeZone;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Columns\Column;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Achievements\Filament\Livewire\AccountRecognitions;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\RecognitionModel;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Policies\AchievementPolicy;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsRecognitions;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Deleting a recognition from the person's page in the panel (D6): no list of every recognition,
 * a permission of its own with no role, names masked by the core's UserPolicy, no game, the month.
 */
final class AccountRecognitionsTest extends ModuleTestCase
{
    use BuildsAchievements;
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
        $this->played('mesa-1', '2026-12-09 18:00:00', $this->ana, $this->bob);
        $this->recognitions()->give($this->ana, InMemoryActivitySource::TYPE, 'mesa-1', $this->handleOf($this->bob), $this->typeId());
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();

        parent::tearDown();
    }

    public function test_the_person_page_shows_them_to_whoever_moderates_recognitions(): void
    {
        $this->actingAs($this->moderator());

        $html = Livewire::test(EditUser::class, ['record' => $this->bob->getRouteKey()])->html();

        $this->assertStringContainsString('wire:name="'.AccountRecognitions::NAME.'"', $html);
    }

    public function test_managing_or_granting_achievements_is_not_enough(): void
    {
        $this->actingAs($this->userWith('users.view_any', 'users.view', 'users.update', AchievementPolicy::MANAGE, AchievementPolicy::GRANT));

        $html = Livewire::test(EditUser::class, ['record' => $this->bob->getRouteKey()])->html();

        $this->assertStringNotContainsString(AccountRecognitions::NAME, $html);
        Livewire::test(AccountRecognitions::class, ['userId' => (string) $this->bob->id])->assertForbidden();
    }

    public function test_the_account_must_be_one_the_moderator_may_view(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MODERATE_RECOGNITIONS));

        Livewire::test(AccountRecognitions::class, ['userId' => (string) $this->bob->id])->assertForbidden();
    }

    public function test_it_lists_given_and_received_with_the_month_and_never_the_game(): void
    {
        $this->actingAs($this->moderator());

        $table = Livewire::test(AccountRecognitions::class, ['userId' => (string) $this->bob->id]);
        $html = $table->html();

        $this->assertStringContainsString('Gran narración', $html);
        $this->assertStringContainsString('12/2026', $html);
        $this->assertStringNotContainsString('mesa-1', $html);
        $this->assertStringNotContainsString('10/12/2026', $html);

        $page = $table->instance();
        $this->assertInstanceOf(AccountRecognitions::class, $page);
        $columns = array_map(static fn (Column $column): ?string => $column->getVisibleFrom(), $page->getTable()->getColumns());
        $this->assertSame(['type.name' => null, 'direction' => null, 'person' => 'sm', 'given_at' => 'md'], $columns);
    }

    public function test_deleting_needs_the_box(): void
    {
        $this->actingAs($this->moderator());
        $record = RecognitionModel::query()->sole();

        Livewire::test(AccountRecognitions::class, ['userId' => (string) $this->bob->id])
            ->callAction(TestAction::make(AccountRecognitions::DELETE_ACTION)->table($record), data: ['confirmation' => false])
            ->assertHasFormErrors(['confirmation' => 'accepted']);
        $this->assertSame(1, $this->recognitionCount());

        Livewire::test(AccountRecognitions::class, ['userId' => (string) $this->bob->id])
            ->callAction(TestAction::make(AccountRecognitions::DELETE_ACTION)->table($record), data: ['confirmation' => true])
            ->assertHasNoFormErrors()
            ->assertNotified(__('achievements::messages.recognitions.panel.delete.done'));
        $this->assertSame(0, $this->recognitionCount());
    }

    public function test_a_read_only_impersonation_refuses_deleting(): void
    {
        $actor = $this->moderator();
        $actor->forceFill(['name' => 'Marta Ejemplo', 'display_name' => 'Marta'])->save();
        $this->actingAs($actor);
        $record = RecognitionModel::query()->sole();
        app(ImpersonationContext::class)->activate(UserModel::factory()->admin()->create(), $actor, ImpersonationMode::ReadOnly, 'log-id');

        Livewire::test(AccountRecognitions::class, ['userId' => (string) $this->bob->id])
            ->callAction(TestAction::make(AccountRecognitions::DELETE_ACTION)->table($record), data: ['confirmation' => true])
            ->assertNotified(__('filament.impersonation_read_only.refused', ['name' => 'Marta']));

        $this->assertSame(1, $this->recognitionCount());
    }

    private function moderator(): UserModel
    {
        return $this->userWith('users.view_any', 'users.view', 'users.update', AchievementPolicy::MODERATE_RECOGNITIONS);
    }
}
