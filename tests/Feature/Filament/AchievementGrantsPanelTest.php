<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Filament;

use App\Domain\Activity\ActivityRole;
use App\Domain\Impersonation\Enums\ImpersonationMode;
use App\Filament\Actions\RowDetailsAction;
use App\Infrastructure\Impersonation\ImpersonationContext;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\DTOs\BulkGrantCountDTO;
use Modules\Achievements\Application\Enums\BulkGrantOutcome;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Events\AchievementGranted;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Filament\Resources\AchievementResource;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\EditAchievement;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\ViewAchievement;
use Modules\Achievements\Filament\Resources\AchievementResource\RelationManagers\GrantLogRelationManager;
use Modules\Achievements\Filament\Resources\AchievementResource\RelationManagers\GrantsRelationManager;
use Modules\Achievements\Filament\Support\GrantActions;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementGrantModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Policies\AchievementPolicy;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\ParticipationActivitySource;
use Tests\Support\Modules\ModuleTestCase;

final class AchievementGrantsPanelTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private AchievementModel $fundadora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutStarterCatalogue();
        ParticipationActivitySource::reset();
        $this->registerSource(ParticipationActivitySource::class);
        $this->registerOnAdminPanel(resources: [AchievementResource::class]);

        $this->fundadora = $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Fundadora']);
        // Off by default
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(bulkGrants: true));
    }

    protected function tearDown(): void
    {
        ParticipationActivitySource::reset();

        parent::tearDown();
    }

    public function test_granting_lets_you_see_the_catalogue_but_secrets_stay_with_whoever_manages_it(): void
    {
        $granter = $this->granter();
        $manager = $this->userWith(AchievementPolicy::MANAGE, AchievementPolicy::GRANT);
        $secret = $this->achievement(['version' => 1, 'metric' => 'manual'], ['is_secret' => true]);
        $off = $this->achievement(['version' => 1, 'metric' => 'manual'], ['is_active' => false]);

        $this->assertTrue(Gate::forUser($granter)->allows('viewAny', AchievementModel::class));
        $this->assertTrue(Gate::forUser($granter)->allows('grant', $this->fundadora));
        $this->assertTrue(Gate::forUser($granter)->allows('viewGrants', $this->fundadora));
        $this->assertFalse(Gate::forUser($granter)->allows('view', $secret));
        $this->assertFalse(Gate::forUser($granter)->allows('grant', $secret));
        $this->assertFalse(Gate::forUser($granter)->allows('revoke', $secret));
        $this->assertTrue(Gate::forUser($manager)->allows('grant', $secret));
        // A switched off achievement can still be taken away, never given
        $this->assertFalse(Gate::forUser($manager)->allows('grant', $off));
        $this->assertTrue(Gate::forUser($manager)->allows('revoke', $off));
    }

    public function test_viewing_the_catalogue_never_shows_who_holds_what(): void
    {
        $viewer = $this->userWith(AchievementPolicy::VIEW_ANY);

        $this->assertFalse(Gate::forUser($viewer)->allows('viewGrants', $this->fundadora));
        $this->assertFalse(Gate::forUser($viewer)->allows('grant', $this->fundadora));
        $this->actingAs($viewer);
        $this->assertFalse(GrantsRelationManager::canViewForRecord($this->fundadora, ViewAchievement::class));
        $this->assertFalse(GrantLogRelationManager::canViewForRecord($this->fundadora, ViewAchievement::class));
    }

    public function test_the_resource_offers_holders_and_history_on_a_view_page(): void
    {
        $this->assertSame([GrantsRelationManager::class, GrantLogRelationManager::class], AchievementResource::getRelations());
        $this->assertArrayHasKey('view', AchievementResource::getPages());

        $this->actingAs($this->granter());
        $this->get(AchievementResource::getUrl('view', ['record' => $this->fundadora]))->assertOk();
    }

    public function test_granting_by_hand_from_the_achievement_logs_who_and_why(): void
    {
        Event::fake([AchievementGranted::class]);
        $actor = $this->granter();
        $this->actingAs($actor);
        $ana = $this->account(self::ANA);

        $this->holders()
            ->callAction(TestAction::make('grantAchievement')->table(), data: ['user_id' => $ana, 'reason' => 'Fundó la asociación', 'notify' => true])
            ->assertHasNoFormErrors()
            ->assertNotified(__('achievements::messages.grants.grant.done'));

        $grant = AchievementGrantModel::query()->sole();
        $this->assertSame($ana, $grant->user_id);
        $this->assertSame('manual', $grant->source);
        $this->assertSame((string) $actor->id, $grant->actor_id);
        $this->assertFalse($grant->silent);
        $this->assertSame('Fundó la asociación', DB::table('achievements_grant_log')->value('reason'));
        Event::assertDispatched(AchievementGranted::class, static fn (AchievementGranted $event): bool => ! $event->silent);
    }

    public function test_an_account_that_already_holds_it_or_is_anonymized_is_not_offered(): void
    {
        $this->actingAs($this->granter());
        $ana = $this->account(self::ANA);
        $this->grantTo($ana);
        $gone = $this->account(self::LUIS, anonymized: true);

        $this->holders()
            ->callAction(TestAction::make('grantAchievement')->table(), data: ['user_id' => $ana])
            ->assertHasFormErrors(['user_id']);
        $this->holders()
            ->callAction(TestAction::make('grantAchievement')->table(), data: ['user_id' => $gone])
            ->assertHasFormErrors(['user_id']);

        $this->assertSame(1, AchievementGrantModel::query()->count());
    }

    public function test_accounts_are_found_and_granted_only_by_whoever_may_see_them(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::GRANT));
        $ana = $this->account(self::ANA);
        UserModel::query()->whereKey($ana)->update(['name' => 'Ana Ejemplo']);

        $search = new \ReflectionMethod(GrantActions::class, 'accountsWithout');
        $this->assertSame([], $search->invoke(null, $this->fundadora, 'Ana'));
        $this->holders()
            ->callAction(TestAction::make('grantAchievement')->table(), data: ['user_id' => $ana])
            ->assertHasFormErrors(['user_id']);
        $this->assertSame(0, AchievementGrantModel::query()->count());

        $this->actingAs($this->userWith(AchievementPolicy::GRANT, 'users.view'));
        $this->assertSame([$ana => 'Ana Ejemplo'], $search->invoke(null, $this->fundadora, 'Ana'));
    }

    public function test_only_whoever_may_grant_sees_the_grant_actions(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        $this->holders()
            ->assertActionHidden(TestAction::make('grantAchievement')->table())
            ->assertActionHidden(TestAction::make('bulkGrant')->table());
    }

    public function test_revoking_needs_a_reason_and_the_box_ticked(): void
    {
        $actor = $this->granter();
        $this->actingAs($actor);
        $grant = $this->grantTo($this->account(self::ANA));
        $record = AchievementGrantModel::query()->findOrFail($grant->id);

        $this->holders()
            ->callAction(TestAction::make('revokeGrant')->table($record), data: ['reason' => '', 'confirmation' => false])
            ->assertHasFormErrors(['reason' => 'required', 'confirmation' => 'accepted']);
        $this->assertSame(1, AchievementGrantModel::query()->count());

        $this->holders()
            ->callAction(TestAction::make('revokeGrant')->table($record), data: ['reason' => 'Concedido por error', 'confirmation' => true])
            ->assertHasNoFormErrors()
            ->assertNotified(__('achievements::messages.grants.revoke.done'));

        $this->assertSame(0, AchievementGrantModel::query()->count());
        $log = DB::table('achievements_grant_log')->where('action', 'revoked')->sole();
        $this->assertSame((string) $actor->id, $log->actor_id);
        $this->assertSame('Concedido por error', $log->reason);
    }

    public function test_the_row_actions_sit_in_one_menu_with_the_details_first(): void
    {
        $this->actingAs($this->granter());
        $actions = $this->holdersTable()->getRecordActions();

        $this->assertCount(1, $actions);
        $this->assertInstanceOf(ActionGroup::class, $actions[0]);
        [$details, $revoke] = $actions[0]->getActions();
        $this->assertInstanceOf(Action::class, $details);
        $this->assertInstanceOf(Action::class, $revoke);
        $this->assertSame(RowDetailsAction::NAME, $details->getName());
        $this->assertSame('revokeGrant', $revoke->getName());
    }

    public function test_the_tables_fit_with_their_secondary_columns_hidden_on_narrow_screens(): void
    {
        $this->actingAs($this->granter());
        $holders = $this->holdersTable()->getColumns();
        $log = $this->logTable(Livewire::test(GrantLogRelationManager::class, ['ownerRecord' => $this->fundadora, 'pageClass' => ViewAchievement::class]))->getColumns();

        $visibleFrom = static fn (array $columns): array => array_map(static fn ($column): ?string => $column->getVisibleFrom(), $columns);

        $this->assertSame(['account' => null, 'source' => 'sm', 'granted_at' => 'md', 'actor' => 'xl'], $visibleFrom($holders));
        $this->assertSame(['created_at' => 'sm', 'action' => null, 'account' => null, 'source' => 'md', 'actor' => 'xl', 'reason' => 'xl'], $visibleFrom($log));
        $this->assertInstanceOf(TextColumn::class, $holders['account']);
        $this->assertTrue($holders['account']->canWrap());
    }

    public function test_bulk_granting_is_hidden_while_it_is_off(): void
    {
        $this->actingAs($this->granter());
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(bulkGrants: false));

        $this->holders()->assertActionHidden(TestAction::make('bulkGrant')->table());
    }

    public function test_bulk_granting_in_silence_from_the_achievement(): void
    {
        Event::fake([AchievementGranted::class]);
        $actor = $this->granter();
        $this->actingAs($actor);
        $this->jornadas([self::ANA, self::LUIS]);

        $this->holders()
            ->callAction(TestAction::make('bulkGrant')->table(), data: [
                'source_type' => ParticipationActivitySource::TYPE,
                'subject_id' => '7',
                'role' => ActivityRole::Attendee->value,
                'notice' => 'silent',
                'reason' => 'Jornadas',
                'confirmation' => true,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified(trans_choice('achievements::messages.grants.bulk.done', 2, ['count' => 2]));

        $this->assertEqualsCanonicalizing([self::ANA, self::LUIS], AchievementGrantModel::query()->pluck('user_id')->all());
        $this->assertSame(['bulk'], AchievementGrantModel::query()->pluck('source')->unique()->values()->all());
        Event::assertNotDispatched(AchievementGranted::class, static fn (AchievementGranted $event): bool => ! $event->silent);
    }

    public function test_bulk_granting_with_a_notice_needs_the_box_ticked(): void
    {
        Event::fake([AchievementGranted::class]);
        $this->actingAs($this->granter());
        $this->jornadas([self::ANA]);
        $data = [
            'source_type' => ParticipationActivitySource::TYPE,
            'subject_id' => '7',
            'role' => ActivityRole::Attendee->value,
            'notice' => 'notify',
        ];

        $this->holders()
            ->callAction(TestAction::make('bulkGrant')->table(), data: $data + ['confirmation' => false])
            ->assertHasFormErrors(['confirmation' => 'accepted']);
        $this->assertSame(0, AchievementGrantModel::query()->count());

        $this->holders()->callAction(TestAction::make('bulkGrant')->table(), data: $data + ['confirmation' => true]);

        $this->assertSame(1, AchievementGrantModel::query()->count());
        Event::assertDispatched(AchievementGranted::class, static fn (AchievementGranted $event): bool => ! $event->silent);
    }

    public function test_the_bulk_dialog_tells_how_many_and_never_who(): void
    {
        $this->actingAs($this->granter());
        UserModel::query()->whereKey($this->account(self::ANA))->update(['name' => 'Ana Asistente', 'display_name' => 'Ana']);
        UserModel::query()->whereKey($this->account(self::LUIS))->update(['name' => 'Luis Asistente', 'display_name' => 'Luis']);
        ParticipationActivitySource::put('7', 'Jornadas 2027', [
            $this->activityFact(self::ANA, ActivityRole::Attendee),
            $this->activityFact(self::LUIS, ActivityRole::Attendee),
        ]);
        $this->ledgerSubject(ParticipationActivitySource::TYPE, '7', 'event', [$this->recordedFact(self::ANA, 'attendee'), $this->recordedFact(self::LUIS, 'attendee')]);

        $this->holders()
            ->mountAction(TestAction::make('bulkGrant')->table())
            ->fillForm([
                'source_type' => ParticipationActivitySource::TYPE,
                'subject_id' => '7',
                'role' => ActivityRole::Attendee->value,
            ])
            ->assertMountedActionModalSee(trans_choice('achievements::messages.grants.bulk.preview.people', 2, ['count' => 2]))
            ->assertMountedActionModalSee(__('achievements::messages.grants.bulk.preview.privacy'))
            ->assertMountedActionModalDontSee('Ana Asistente')
            ->assertMountedActionModalDontSee('Luis Asistente');
    }

    public function test_a_subject_the_granter_may_not_see_is_never_offered(): void
    {
        $this->actingAs($this->granter());
        $this->jornadas([self::ANA], hidden: true);

        $this->holders()
            ->callAction(TestAction::make('bulkGrant')->table(), data: [
                'source_type' => ParticipationActivitySource::TYPE,
                'subject_id' => '7',
                'role' => ActivityRole::Attendee->value,
                'notice' => 'silent',
                'confirmation' => true,
            ])
            ->assertHasFormErrors(['subject_id']);

        $this->assertSame(0, AchievementGrantModel::query()->count());
    }

    public function test_the_history_lists_what_happened_and_offers_no_changes(): void
    {
        $actor = $this->granter();
        $this->actingAs($actor);
        UserModel::query()->whereKey($this->account(self::ANA))->update(['name' => 'Ana Ejemplo']);
        $grant = $this->grantTo(self::ANA, reason: 'Fundó la asociación');
        $this->app->make(AchievementGranterInterface::class)->revoke($grant->id, (string) $actor->id, 'Concedido por error');

        $log = Livewire::test(GrantLogRelationManager::class, ['ownerRecord' => $this->fundadora, 'pageClass' => ViewAchievement::class])
            ->assertCanSeeTableRecords(DB::table('achievements_grant_log')->pluck('id')->all())
            ->assertSee('Ana Ejemplo')
            ->assertSee('Concedido por error');

        $actions = $this->logTable($log)->getRecordActions();
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(Action::class, $actions[0]);
        $this->assertSame(RowDetailsAction::NAME, $actions[0]->getName());
        $this->assertSame([], $this->logTable($log)->getHeaderActions());
    }

    public function test_a_read_only_impersonation_refuses_every_grant_and_revoke(): void
    {
        $actor = $this->granter();
        $actor->forceFill(['name' => 'Marta Ejemplo', 'display_name' => 'Marta'])->save();
        $this->actingAs($actor);
        $this->jornadas([self::ANA]);
        $luis = $this->account(self::LUIS);
        $grant = AchievementGrantModel::query()->findOrFail($this->grantTo($this->account(self::MARTA))->id);
        app(ImpersonationContext::class)->activate(UserModel::factory()->admin()->create(), $actor, ImpersonationMode::ReadOnly, 'log-id');
        $refused = __('filament.impersonation_read_only.refused', ['name' => 'Marta']);

        $this->holders()
            ->callAction(TestAction::make('grantAchievement')->table(), data: ['user_id' => $luis])
            ->assertNotified($refused);
        $this->holders()
            ->callAction(TestAction::make('revokeGrant')->table($grant), data: ['reason' => 'Motivo', 'confirmation' => true])
            ->assertNotified($refused);
        $this->holders()
            ->callAction(TestAction::make('bulkGrant')->table(), data: [
                'source_type' => ParticipationActivitySource::TYPE,
                'subject_id' => '7',
                'role' => ActivityRole::Attendee->value,
                'notice' => 'silent',
                'confirmation' => true,
            ])
            ->assertNotified($refused);

        $this->assertSame([self::MARTA], AchievementGrantModel::query()->pluck('user_id')->all());
        $this->assertSame(1, DB::table('achievements_grant_log')->count());
    }

    public function test_an_account_the_granter_may_not_see_shows_hidden_and_cannot_be_revoked(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::GRANT));
        UserModel::query()->whereKey($this->account(self::ANA))->update(['name' => 'Ana Ejemplo']);
        $record = AchievementGrantModel::query()->findOrFail($this->grantTo(self::ANA)->id);

        $this->holders()
            ->assertSee(__('achievements::messages.grants.log.hidden_account'))
            ->assertDontSee('Ana Ejemplo')
            ->assertActionHidden(TestAction::make('revokeGrant')->table($record));
        Livewire::test(GrantLogRelationManager::class, ['ownerRecord' => $this->fundadora, 'pageClass' => ViewAchievement::class])
            ->assertSee(__('achievements::messages.grants.log.hidden_account'))
            ->assertDontSee('Ana Ejemplo');
        $this->assertFalse($this->holdersTable()->getColumn('account')?->isSearchable());
        // Nor can its name be read through the account picker's label
        $label = new \ReflectionMethod(GrantActions::class, 'visibleAccountName');
        $this->assertNull($label->invoke(null, self::ANA));

        $this->actingAs($this->granter());
        $this->assertTrue($this->holdersTable()->getColumn('account')?->isSearchable());
        $this->assertSame('Ana Ejemplo', $label->invoke(null, self::ANA));
    }

    public function test_holders_are_found_by_their_public_name_too(): void
    {
        $this->actingAs($this->granter());
        UserModel::query()->whereKey($this->account(self::ANA))->update(['name' => 'Ana Ejemplo', 'display_name' => 'Anita']);
        UserModel::query()->whereKey($this->account(self::LUIS))->update(['name' => 'Luis Ejemplo', 'display_name' => 'Lu']);
        $ana = AchievementGrantModel::query()->findOrFail($this->grantTo(self::ANA)->id);
        $luis = AchievementGrantModel::query()->findOrFail($this->grantTo(self::LUIS)->id);

        $this->holders()
            ->searchTable('anita')
            ->assertCanSeeTableRecords([$ana])
            ->assertCanNotSeeTableRecords([$luis]);
    }

    public function test_the_preview_tells_how_many_get_it_hold_it_or_had_it_taken_away(): void
    {
        $preview = new BulkGrantCountDTO(BulkGrantOutcome::Ready, people: 5, alreadyHeld: 1, revoked: 1);

        $this->assertSame([
            trans_choice('achievements::messages.grants.bulk.preview.people', 3, ['count' => 3]),
            trans_choice('achievements::messages.grants.bulk.preview.held', 1, ['count' => 1]),
            trans_choice('achievements::messages.grants.bulk.preview.revoked', 1, ['count' => 1]),
        ], GrantActions::previewLines($preview));
    }

    private function granter(): UserModel
    {
        return $this->userWith(AchievementPolicy::GRANT, 'users.view_any', 'users.view');
    }

    /**
     * @return Testable<GrantsRelationManager>
     */
    private function holders(): Testable
    {
        return Livewire::test(GrantsRelationManager::class, ['ownerRecord' => $this->fundadora, 'pageClass' => EditAchievement::class]);
    }

    private function holdersTable(): Table
    {
        return $this->holders()->instance()->getTable();
    }

    /**
     * @param  Testable<GrantLogRelationManager>  $log
     */
    private function logTable(Testable $log): Table
    {
        return $log->instance()->getTable();
    }

    private function grantTo(string $userId, ?string $reason = null): AchievementGrant
    {
        $grant = $this->app->make(AchievementGranterInterface::class)->grant(new NewGrant($userId, $this->fundadora->id, GrantSource::Manual, silent: true, reason: $reason));
        $this->assertInstanceOf(AchievementGrant::class, $grant);

        return $grant;
    }

    /**
     * @param  list<string>  $attendees
     */
    private function jornadas(array $attendees, bool $hidden = false): void
    {
        $facts = [];
        $recorded = [];

        foreach ($attendees as $id) {
            $this->account($id);
            $facts[] = $this->activityFact($id, ActivityRole::Attendee);
            $recorded[] = $this->recordedFact($id, 'attendee');
        }

        ParticipationActivitySource::put('7', 'Jornadas 2027', $facts, $hidden);
        $this->ledgerSubject(ParticipationActivitySource::TYPE, '7', 'event', $recorded);
    }
}
