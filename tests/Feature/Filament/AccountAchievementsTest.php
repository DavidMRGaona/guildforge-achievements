<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Filament;

use App\Domain\Impersonation\Enums\ImpersonationMode;
use App\Filament\Actions\RowDetailsAction;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Infrastructure\Impersonation\ImpersonationContext;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Filament\Livewire\AccountAchievements;
use Modules\Achievements\Filament\Livewire\AccountGrantLog;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementGrantModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Policies\AchievementPolicy;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Tests\Support\Modules\ModuleTestCase;

final class AccountAchievementsTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    private AchievementModel $fundadora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutStarterCatalogue();

        $this->ana = UserModel::factory()->create(['name' => 'Ana Ejemplo']);
        $this->fundadora = $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Fundadora']);
    }

    public function test_the_user_page_shows_the_achievements_to_whoever_may_grant_them(): void
    {
        $this->actingAs($this->userWith('users.view_any', 'users.view', 'users.update', AchievementPolicy::GRANT));

        $html = Livewire::test(EditUser::class, ['record' => $this->ana->getRouteKey()])->html();

        $this->assertStringContainsString('wire:name="'.AccountAchievements::NAME.'"', $html);
        $this->assertStringContainsString('wire:name="'.AccountGrantLog::NAME.'"', $html);
    }

    public function test_the_user_page_shows_nothing_of_it_to_whoever_may_not(): void
    {
        $this->actingAs($this->userWith('users.view_any', 'users.view', 'users.update', AchievementPolicy::VIEW_ANY));

        $html = Livewire::test(EditUser::class, ['record' => $this->ana->getRouteKey()])->html();

        $this->assertStringNotContainsString(AccountAchievements::NAME, $html);
        $this->assertStringNotContainsString(AccountGrantLog::NAME, $html);
    }

    public function test_the_component_refuses_whoever_may_not_grant(): void
    {
        $this->actingAs($this->userWith('users.view_any', 'users.view', AchievementPolicy::VIEW_ANY));

        Livewire::test(AccountAchievements::class, ['userId' => (string) $this->ana->id])->assertForbidden();
        Livewire::test(AccountGrantLog::class, ['userId' => (string) $this->ana->id])->assertForbidden();
    }

    public function test_granting_from_the_account_logs_who_and_why(): void
    {
        $actor = $this->granter();
        $this->actingAs($actor);

        $this->account()
            ->callAction(TestAction::make('grantAchievement')->table(), data: ['achievement_id' => $this->fundadora->id, 'reason' => 'Socia fundadora', 'notify' => false])
            ->assertHasNoFormErrors()
            ->assertNotified(__('achievements::messages.grants.grant.done'));

        $grant = AchievementGrantModel::query()->sole();
        $this->assertSame((string) $this->ana->id, $grant->user_id);
        $this->assertSame((string) $actor->id, $grant->actor_id);
        $this->assertTrue($grant->silent);
        $this->assertSame('Socia fundadora', DB::table('achievements_grant_log')->value('reason'));
    }

    public function test_only_achievements_the_person_may_grant_are_offered(): void
    {
        $this->actingAs($this->granter());
        $secret = $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Secreto', 'is_secret' => true]);
        $off = $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Apagado', 'is_active' => false]);
        $held = $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Ya lo tiene']);
        $this->grant($held);

        foreach ([$secret, $off, $held] as $refused) {
            $this->account()
                ->callAction(TestAction::make('grantAchievement')->table(), data: ['achievement_id' => $refused->id])
                ->assertHasFormErrors(['achievement_id']);
        }

        $this->assertSame(1, AchievementGrantModel::query()->count());
    }

    public function test_whoever_manages_the_catalogue_may_grant_a_secret_one(): void
    {
        $this->actingAs($this->userWith('users.view_any', 'users.view', AchievementPolicy::GRANT, AchievementPolicy::MANAGE));
        $secret = $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Secreto', 'is_secret' => true]);

        $this->account()
            ->callAction(TestAction::make('grantAchievement')->table(), data: ['achievement_id' => $secret->id])
            ->assertHasNoFormErrors();

        $this->assertSame(1, AchievementGrantModel::query()->count());
    }

    public function test_revoking_from_the_account_needs_a_reason_and_the_box_ticked(): void
    {
        $this->actingAs($this->granter());
        $record = AchievementGrantModel::query()->findOrFail($this->grant($this->fundadora)->id);

        $this->account()
            ->callAction(TestAction::make('revokeGrant')->table($record), data: ['reason' => '  ', 'confirmation' => true])
            ->assertHasFormErrors(['reason']);
        $this->account()
            ->callAction(TestAction::make('revokeGrant')->table($record), data: ['reason' => 'Por error', 'confirmation' => true])
            ->assertHasNoFormErrors();

        $this->assertSame(0, AchievementGrantModel::query()->count());
        $this->assertSame('Por error', DB::table('achievements_grant_log')->where('action', 'revoked')->value('reason'));
    }

    public function test_a_secret_one_shows_masked_and_cannot_be_revoked_by_whoever_only_grants(): void
    {
        $this->actingAs($this->granter());
        $secret = $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Nombre oculto', 'is_secret' => true]);
        $record = AchievementGrantModel::query()->findOrFail($this->grant($secret)->id);

        $this->account()
            ->assertSee(__('achievements::messages.secret.name'))
            ->assertDontSee('Nombre oculto')
            ->assertActionHidden(TestAction::make('revokeGrant')->table($record));
        Livewire::test(AccountGrantLog::class, ['userId' => (string) $this->ana->id])
            ->assertSee(__('achievements::messages.secret.name'))
            ->assertDontSee('Nombre oculto');
    }

    public function test_the_row_actions_sit_in_one_menu_with_the_details_first(): void
    {
        $this->actingAs($this->granter());
        $actions = $this->account()->instance()->getTable()->getRecordActions();

        $this->assertCount(1, $actions);
        $this->assertInstanceOf(ActionGroup::class, $actions[0]);
        $details = $actions[0]->getActions()[0];
        $this->assertInstanceOf(Action::class, $details);
        $this->assertSame(RowDetailsAction::NAME, $details->getName());
    }

    public function test_the_history_of_the_account_is_read_only(): void
    {
        $actor = $this->granter();
        $this->actingAs($actor);
        $grant = $this->grant($this->fundadora, reason: 'Primera socia');
        $this->app->make(AchievementGranterInterface::class)->revoke($grant->id, (string) $actor->id, 'Por error');

        $log = Livewire::test(AccountGrantLog::class, ['userId' => (string) $this->ana->id])
            ->assertCanSeeTableRecords(DB::table('achievements_grant_log')->pluck('id')->all())
            ->assertSee('Fundadora')
            ->assertSee('Primera socia')
            ->assertSee('Por error');

        $component = $log->instance();
        $this->assertInstanceOf(AccountGrantLog::class, $component);
        $this->assertSame([], $component->getTable()->getHeaderActions());
        $actions = $component->getTable()->getRecordActions();
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(Action::class, $actions[0]);
        $this->assertSame(RowDetailsAction::NAME, $actions[0]->getName());
    }

    public function test_a_read_only_impersonation_refuses_granting_and_revoking_from_the_account(): void
    {
        $actor = $this->granter();
        $actor->forceFill(['name' => 'Marta Ejemplo', 'display_name' => 'Marta'])->save();
        $this->actingAs($actor);
        $other = $this->achievement(['version' => 1, 'metric' => 'manual']);
        $record = AchievementGrantModel::query()->findOrFail($this->grant($this->fundadora)->id);
        app(ImpersonationContext::class)->activate(UserModel::factory()->admin()->create(), $actor, ImpersonationMode::ReadOnly, 'log-id');
        $refused = __('filament.impersonation_read_only.refused', ['name' => 'Marta']);

        $this->account()
            ->callAction(TestAction::make('grantAchievement')->table(), data: ['achievement_id' => $other->id])
            ->assertNotified($refused);
        $this->account()
            ->callAction(TestAction::make('revokeGrant')->table($record), data: ['reason' => 'Motivo', 'confirmation' => true])
            ->assertNotified($refused);

        $this->assertSame([$this->fundadora->id], AchievementGrantModel::query()->pluck('achievement_id')->all());
    }

    private function granter(): UserModel
    {
        return $this->userWith('users.view_any', 'users.view', AchievementPolicy::GRANT);
    }

    /**
     * @return Testable<AccountAchievements>
     */
    private function account(): Testable
    {
        return Livewire::test(AccountAchievements::class, ['userId' => (string) $this->ana->id]);
    }

    private function grant(AchievementModel $achievement, ?string $reason = null): AchievementGrant
    {
        $grant = $this->app->make(AchievementGranterInterface::class)->grant(new NewGrant((string) $this->ana->id, $achievement->id, GrantSource::Manual, silent: true, reason: $reason));
        $this->assertInstanceOf(AchievementGrant::class, $grant);

        return $grant;
    }
}
