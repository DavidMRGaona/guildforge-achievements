<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Filament;

use App\Domain\Impersonation\Enums\ImpersonationMode;
use App\Filament\Actions\RowDetailsAction;
use App\Filament\Support\PanelNavigationGroup;
use App\Infrastructure\Impersonation\ImpersonationContext;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\Column;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Achievements\AchievementsServiceProvider;
use Modules\Achievements\Domain\Repositories\ChallengeRepositoryInterface;
use Modules\Achievements\Filament\Resources\AchievementResource;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\EditAchievement;
use Modules\Achievements\Filament\Resources\ChallengeResource;
use Modules\Achievements\Filament\Resources\ChallengeResource\Pages\CreateChallenge;
use Modules\Achievements\Filament\Resources\ChallengeResource\Pages\EditChallenge;
use Modules\Achievements\Filament\Resources\ChallengeResource\Pages\ListChallenges;
use Modules\Achievements\Filament\Support\ChallengeForm;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\ChallengeModel;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Jobs\EvaluateChallengesJob;
use Modules\Achievements\Policies\AchievementPolicy;
use Modules\Achievements\Policies\ChallengePolicy;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsChallenges;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;

final class ChallengeResourceTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use BuildsChallenges;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutStarterCatalogue();
        $this->registerInMemorySource();
        Bus::fake([EvaluateAchievementsJob::class, EvaluateChallengesJob::class]);
        $this->switchChallenges();
        $this->registerOnAdminPanel(resources: [AchievementResource::class, ChallengeResource::class]);
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();

        parent::tearDown();
    }

    public function test_it_sits_under_achievements_in_the_people_group(): void
    {
        $this->assertSame(PanelNavigationGroup::People->getLabel(), ChallengeResource::getNavigationGroup());
        $this->assertSame(AchievementResource::getNavigationLabel(), ChallengeResource::getNavigationParentItem());
        $this->assertSame('Retos', ChallengeResource::getNavigationLabel());
    }

    public function test_the_provider_registers_its_policy(): void
    {
        $this->assertSame(ChallengePolicy::class, new AchievementsServiceProvider($this->app)->registerPolicies()[ChallengeModel::class] ?? null);
    }

    public function test_viewing_needs_view_any_or_manage_and_writing_needs_manage(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::VIEW_ANY));
        $this->assertTrue(ChallengeResource::canAccess());
        $this->assertFalse(ChallengeResource::canCreate());

        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $this->assertTrue(ChallengeResource::canCreate());

        $this->actingAs($this->userWith(AchievementPolicy::GRANT));
        $this->assertFalse(ChallengeResource::canAccess());
    }

    public function test_it_is_gone_with_the_switch_off(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $this->switchChallenges(false);

        $this->assertFalse(ChallengeResource::canAccess());
        $this->get(ChallengeResource::getUrl('index'))->assertForbidden();
    }

    public function test_creating_stores_the_rule_and_counts_it(): void
    {
        $manager = $this->userWith(AchievementPolicy::MANAGE);
        $this->actingAs($manager);
        $reward = $this->reward(['name' => 'Diciembre en la mesa']);

        Livewire::test(CreateChallenge::class)
            ->fillForm([
                'name' => '40 partidas en diciembre',
                'description' => 'Entre todos',
                'starts_on' => '2026-12-01',
                'ends_on' => '2026-12-31',
                'audience' => 'members',
                'reward_achievement_id' => $reward->id,
                'rule_kind' => 'game_session',
                'rule_metric' => 'subjects',
                'rule_role' => 'player',
                'rule_target' => 40,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $challenge = ChallengeModel::query()->sole();
        $this->assertSame(
            ['version' => 1, 'metric' => 'subjects', 'kind' => 'game_session', 'filters' => ['role' => 'player'], 'target' => 40],
            $challenge->rule,
        );
        $this->assertSame('members', $challenge->audience);
        $this->assertSame('2026-12-31', $challenge->ends_on->format('Y-m-d'));
        $this->assertSame(strtolower((string) $manager->id), strtolower((string) $challenge->created_by));
        Bus::assertDispatched(EvaluateChallengesJob::class, static fn (EvaluateChallengesJob $job): bool => $job->challengeIds === [$challenge->id]);
    }

    public function test_the_reward_is_an_active_manual_achievement_that_is_not_secret(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $manual = $this->reward(['name' => 'Manual']);
        $byRule = $this->achievement($this->countRule(), ['name' => 'Por regla']);
        $secret = $this->reward(['name' => 'Secreto', 'is_secret' => true]);
        $off = $this->reward(['name' => 'Apagado', 'is_active' => false]);

        $this->assertSame([$manual->id => 'Manual'], ChallengeForm::rewardOptions(null));

        foreach ([$byRule, $secret, $off] as $refused) {
            Livewire::test(CreateChallenge::class)
                ->fillForm($this->formData(['reward_achievement_id' => $refused->id]))
                ->call('create')
                ->assertHasFormErrors(['reward_achievement_id']);
        }

        $this->assertSame(0, ChallengeModel::query()->count());
    }

    public function test_the_last_day_cannot_come_before_the_first(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        Livewire::test(CreateChallenge::class)
            ->fillForm($this->formData(['starts_on' => '2026-12-10', 'ends_on' => '2026-12-01']))
            ->call('create')
            ->assertHasFormErrors(['ends_on']);
    }

    public function test_a_challenge_lasts_at_least_28_days_counting_both_ends(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        // 1–27 February: 27 days
        Livewire::test(CreateChallenge::class)
            ->fillForm($this->formData(['starts_on' => '2027-02-01', 'ends_on' => '2027-02-27']))
            ->call('create')
            ->assertHasFormErrors(['ends_on']);
        $this->assertSame(0, ChallengeModel::query()->count());

        // 1–28 February: 28 days
        Livewire::test(CreateChallenge::class)
            ->fillForm($this->formData(['starts_on' => '2027-02-01', 'ends_on' => '2027-02-28']))
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(1, ChallengeModel::query()->count());
    }

    public function test_the_server_refuses_a_short_challenge_whatever_the_form_checked(): void
    {
        $this->expectException(ValidationException::class);

        ChallengeForm::assertLongEnough(['starts_on' => '2026-12-01', 'ends_on' => '2026-12-27']);
    }

    public function test_a_short_challenge_stored_before_the_rule_is_not_saved_again_until_it_lasts_enough(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $challenge = $this->challenge([], ['starts_on' => '2026-12-01', 'ends_on' => '2026-12-07']);

        Livewire::test(EditChallenge::class, ['record' => $challenge->id])
            ->fillForm(['name' => 'Semana'])
            ->call('save')
            ->assertHasFormErrors(['ends_on']);
        $this->assertNotSame('Semana', $challenge->refresh()->name);

        Livewire::test(EditChallenge::class, ['record' => $challenge->id])
            ->fillForm(['name' => 'Mes', 'ends_on' => '2026-12-28'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Mes', $challenge->refresh()->name);
    }

    public function test_a_completed_short_challenge_still_changes_its_name(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $challenge = $this->challenge([], ['starts_on' => '2026-12-01', 'ends_on' => '2026-12-07']);
        ChallengeModel::query()->whereKey($challenge->id)->update(['completed_at' => '2026-12-05 10:00:00']);

        Livewire::test(EditChallenge::class, ['record' => $challenge->id])
            ->fillForm(['name' => 'Semana'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Semana', $challenge->refresh()->name);
        $this->assertSame('2026-12-07', $challenge->ends_on->format('Y-m-d'));
    }

    public function test_editing_keeps_the_rule_fields(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $challenge = $this->challenge(['metric' => 'people', 'target' => 12]);

        Livewire::test(EditChallenge::class, ['record' => $challenge->id])
            ->assertSchemaStateSet(['rule_metric' => 'people', 'rule_kind' => 'game_session', 'rule_target' => 12])
            ->fillForm(['rule_target' => 15])
            ->call('save')
            ->assertHasNoFormErrors();

        $rule = $challenge->refresh()->rule;
        $this->assertIsArray($rule);
        $this->assertSame(15, $rule['target']);
    }

    public function test_another_audience_never_shows_the_count_of_the_old_one(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $challenge = $this->challenge([], ['audience' => 'members']);
        $ana = $this->account();
        ChallengeModel::query()->whereKey($challenge->id)->update([
            'progress' => 7, 'contributors' => 3, 'progress_at' => '2026-12-20 09:00:00',
            'shown_progress' => 5, 'shown_contributors' => 2, 'shown_at' => '2026-12-20 04:10:00',
        ]);
        DB::table('achievements_challenge_contributors')->insert([
            'challenge_id' => $challenge->id, 'user_id' => $ana, 'contribution' => 2,
            'last_contribution_at' => '2026-12-10 18:00:00', 'created_at' => '2026-12-10 18:00:00', 'updated_at' => '2026-12-10 18:00:00',
        ]);
        $version = $challenge->refresh()->count_version;

        Livewire::test(EditChallenge::class, ['record' => $challenge->id])
            ->fillForm(['audience' => 'everybody'])
            ->call('save')
            ->assertHasNoFormErrors();

        $challenge->refresh();
        $this->assertSame($version + 1, $challenge->count_version);
        $this->assertSame([0, 0, null, 0, 0, null], [$challenge->progress, $challenge->contributors, $challenge->progress_at, $challenge->shown_progress, $challenge->shown_contributors, $challenge->shown_at]);
        $this->assertSame(0, DB::table('achievements_challenge_contributors')->where('challenge_id', $challenge->id)->count());
        Bus::assertDispatched(EvaluateChallengesJob::class, static fn (EvaluateChallengesJob $job): bool => $job->challengeIds === [$challenge->id]);
    }

    public function test_who_cannot_grant_sees_a_challenge_of_members_as_the_nightly_count_left_it(): void
    {
        $challenge = $this->challenge(['target' => 40], ['audience' => 'members']);
        ChallengeModel::query()->whereKey($challenge->id)->update([
            'progress' => 7, 'contributors' => 3, 'progress_at' => '2026-12-20 09:00:00',
            'shown_progress' => 5, 'shown_contributors' => 2, 'shown_at' => '2026-12-20 04:10:00',
        ]);
        $challenge->refresh();

        $this->actingAs($this->userWith(AchievementPolicy::VIEW_ANY));
        $this->assertSame(ChallengeResource::progressSummary($this->challengeWith(5)), ChallengeResource::progressSummary($challenge));
        $this->assertSame(2, ChallengeResource::seen($challenge)->contributors);

        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $this->assertSame(7, ChallengeResource::seen($challenge)->progress);
        $this->assertSame(3, ChallengeResource::seen($challenge)->contributors);
    }

    public function test_the_count_version_goes_up_by_one_per_change_in_sql(): void
    {
        $challenge = $this->challenge();
        $version = $challenge->refresh()->count_version;
        // Somebody else saved it meanwhile
        ChallengeModel::query()->whereKey($challenge->id)->update(['count_version' => $version + 5]);

        $challenge->update(['ends_on' => '2027-01-10']);

        $this->assertSame($version + 6, $challenge->count_version);
        $this->assertSame($version + 6, $challenge->refresh()->count_version);
        $challenge->update(['name' => 'Otro nombre']);
        $this->assertSame($version + 6, $challenge->refresh()->count_version);
    }

    public function test_closing_needs_the_box_and_is_for_good(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $challenge = $this->challenge();

        Livewire::test(EditChallenge::class, ['record' => $challenge->id])
            ->callAction(ChallengeResource::CLOSE_ACTION, ['confirmation' => false])
            ->assertHasActionErrors(['confirmation']);
        $this->assertNull($challenge->refresh()->closed_at);

        Livewire::test(EditChallenge::class, ['record' => $challenge->id])
            ->callAction(ChallengeResource::CLOSE_ACTION, ['confirmation' => true])
            ->assertHasNoActionErrors();

        $challenge->refresh();
        $this->assertNotNull($challenge->closed_at);
        $user = auth()->user();
        $this->assertInstanceOf(UserModel::class, $user);
        $this->assertFalse($user->can('update', $challenge));
        $this->assertFalse($user->can('close', $challenge));
    }

    public function test_only_a_challenge_never_completed_is_deleted(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $completed = $this->challenge();
        ChallengeModel::query()->whereKey($completed->id)->update(['completed_at' => '2026-12-10 10:00:00']);
        $open = $this->challenge();

        Livewire::test(EditChallenge::class, ['record' => $completed->id])->assertActionHidden(DeleteAction::class);
        Livewire::test(EditChallenge::class, ['record' => $open->id])
            ->callAction(DeleteAction::class, ['confirmation' => true]);

        $this->assertSame([$completed->id], ChallengeModel::query()->pluck('id')->all());
    }

    public function test_deleting_needs_the_box(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $challenge = $this->challenge();

        Livewire::test(EditChallenge::class, ['record' => $challenge->id])
            ->callAction(DeleteAction::class, ['confirmation' => false])
            ->assertHasActionErrors(['confirmation']);

        $this->assertSame(1, ChallengeModel::query()->count());
    }

    public function test_a_challenge_completed_while_the_dialog_was_open_is_not_deleted(): void
    {
        $challenge = $this->challenge();
        ChallengeModel::query()->whereKey($challenge->id)->update(['completed_at' => '2026-12-10 10:00:00']);

        $this->assertFalse($this->app->make(ChallengeRepositoryInterface::class)->deleteUnlessCompleted($challenge->id));
        $this->assertSame(1, ChallengeModel::query()->count());
    }

    public function test_a_completed_challenge_keeps_what_it_counts_and_only_its_name_changes(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $challenge = $this->challenge(['target' => 12]);
        $reward = $challenge->reward_achievement_id;
        ChallengeModel::query()->whereKey($challenge->id)->update(['completed_at' => '2026-12-10 10:00:00']);
        $other = $this->reward();

        $component = Livewire::test(EditChallenge::class, ['record' => $challenge->id])
            ->assertFormFieldIsDisabled('rule_target')
            ->assertFormFieldIsDisabled('reward_achievement_id')
            ->assertFormFieldIsDisabled('ends_on')
            ->assertFormFieldIsEnabled('name');
        // Whatever the form sends for what is locked is dropped on the server
        $component->set('data.rule_target', 99)->set('data.reward_achievement_id', $other->id)->set('data.ends_on', '2027-02-01')
            ->fillForm(['name' => 'Otro nombre'])
            ->call('save')
            ->assertHasNoFormErrors();

        $challenge->refresh();
        $this->assertSame('Otro nombre', $challenge->name);
        $this->assertSame(12, is_array($challenge->rule) ? $challenge->rule['target'] : null);
        $this->assertSame($reward, $challenge->reward_achievement_id);
        $this->assertSame('2026-12-31', $challenge->ends_on->format('Y-m-d'));
        $user = auth()->user();
        $this->assertInstanceOf(UserModel::class, $user);
        $this->assertFalse($user->can('changeWhatCounts', $challenge));
    }

    public function test_changing_the_reward_of_an_open_challenge_counts_it_again(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $challenge = $this->challenge();
        $other = $this->reward();
        new UniqueLock($this->app->make(Repository::class))->release(new EvaluateChallengesJob([$challenge->id], false));
        Bus::fake([EvaluateChallengesJob::class]);

        Livewire::test(EditChallenge::class, ['record' => $challenge->id])
            ->fillForm(['reward_achievement_id' => $other->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($other->id, $challenge->refresh()->reward_achievement_id);
        Bus::assertDispatched(EvaluateChallengesJob::class, static fn (EvaluateChallengesJob $job): bool => $job->challengeIds === [$challenge->id]);
    }

    public function test_the_reward_of_a_challenge_still_counted_stays_a_reward(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $challenge = $this->challenge();
        $reward = AchievementModel::query()->findOrFail($challenge->reward_achievement_id);

        foreach ([
            'is_secret' => ['is_secret' => true],
            'is_active' => ['is_active' => false],
            'rule_metric' => ['rule_metric' => 'count', 'rule_kind' => 'game_session', 'rule_threshold' => 1],
            // Once per season: the challenge would grant it without one
            'rule_window' => ['rule_window' => 'season'],
        ] as $field => $change) {
            Livewire::test(EditAchievement::class, ['record' => $reward->id])
                ->fillForm($change)
                ->call('save')
                ->assertHasFormErrors([$field]);
        }

        $reward->refresh();
        $this->assertTrue($reward->is_active);
        $this->assertFalse($reward->is_secret);

        // Once the challenge is closed, it is an achievement like any other
        ChallengeModel::query()->whereKey($challenge->id)->update(['closed_at' => '2026-12-10 10:00:00']);
        Livewire::test(EditAchievement::class, ['record' => $reward->id])
            ->fillForm(['is_secret' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue($reward->refresh()->is_secret);
    }

    public function test_bringing_back_a_challenge_needs_a_reward_that_is_still_one(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        // Past its late facts' days: its reward was free to stop being one meanwhile
        $challenge = $this->challenge([], ['starts_on' => '2026-01-01', 'ends_on' => '2026-01-31']);
        AchievementModel::query()->whereKey($challenge->reward_achievement_id)->update(['is_secret' => true]);

        Livewire::test(EditChallenge::class, ['record' => $challenge->id])
            ->fillForm(['ends_on' => '2027-01-31'])
            ->call('save')
            ->assertHasFormErrors(['reward_achievement_id']);
        $this->assertSame('2026-01-31', $challenge->refresh()->ends_on->format('Y-m-d'));

        // Its name alone may still change
        Livewire::test(EditChallenge::class, ['record' => $challenge->id])
            ->fillForm(['name' => 'Enero'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Enero', $challenge->refresh()->name);
    }

    public function test_an_achievement_that_rewards_a_challenge_is_not_deleted(): void
    {
        $manager = $this->userWith(AchievementPolicy::MANAGE);
        $challenge = $this->challenge();
        $reward = AchievementModel::query()->findOrFail($challenge->reward_achievement_id);

        $this->assertFalse($manager->can('delete', $reward));
    }

    public function test_the_table_fits_narrow_screens(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $page = Livewire::test(ListChallenges::class)->instance();
        $this->assertInstanceOf(ListChallenges::class, $page);
        $table = $page->getTable();

        $actions = $table->getRecordActions();
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(ActionGroup::class, $actions[0]);
        $first = $actions[0]->getActions()[0];
        $this->assertInstanceOf(Action::class, $first);
        $this->assertSame(RowDetailsAction::NAME, $first->getName());

        $visibleFrom = array_map(static fn (Column $column): ?string => $column->getVisibleFrom(), $table->getColumns());
        $this->assertSame(
            ['name' => null, 'state' => null, 'progress' => 'sm', 'days' => 'md', 'what' => 'xl', 'reward.name' => 'xl', 'contributors' => '2xl'],
            $visibleFrom,
        );
    }

    public function test_a_read_only_impersonation_refuses_closing(): void
    {
        $actor = $this->userWith(AchievementPolicy::MANAGE);
        $actor->forceFill(['name' => 'Marta Ejemplo', 'display_name' => 'Marta'])->save();
        $this->actingAs($actor);
        $challenge = $this->challenge();
        app(ImpersonationContext::class)->activate(UserModel::factory()->admin()->create(), $actor, ImpersonationMode::ReadOnly, 'log-id');

        Livewire::test(EditChallenge::class, ['record' => $challenge->id])
            ->callAction(ChallengeResource::CLOSE_ACTION, ['confirmation' => true])
            ->assertNotified(__('filament.impersonation_read_only.refused', ['name' => 'Marta']));

        $this->assertNull($challenge->refresh()->closed_at);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function formData(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Reto',
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-31',
            'audience' => 'everybody',
            'reward_achievement_id' => $this->reward()->id,
            'rule_kind' => 'game_session',
            'rule_metric' => 'subjects',
            'rule_target' => 10,
        ];
    }

    /**
     * A challenge for everybody counted at this total, to compare the panel's text with.
     */
    private function challengeWith(int $progress): ChallengeModel
    {
        $challenge = $this->challenge(['target' => 40]);
        ChallengeModel::query()->whereKey($challenge->id)->update(['progress' => $progress, 'progress_at' => '2026-12-20 09:00:00']);

        return $challenge->refresh();
    }
}
