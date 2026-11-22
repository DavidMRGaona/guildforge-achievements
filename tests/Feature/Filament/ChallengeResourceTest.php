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
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Achievements\AchievementsServiceProvider;
use Modules\Achievements\Filament\Resources\AchievementResource;
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
}
