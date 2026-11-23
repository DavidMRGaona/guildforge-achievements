<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Seasons;

use App\Domain\Activity\ActivityRole;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Filament\Resources\AchievementResource;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\CreateAchievement;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\EditAchievement;
use Modules\Achievements\Filament\Resources\AchievementResource\RelationManagers\GrantsRelationManager;
use Modules\Achievements\Filament\Support\AchievementPanelView;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementGrantModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Policies\AchievementPolicy;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\ParticipationActivitySource;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Seasons and streaks in the panel: the rule builder, the summary of the catalogue, and grants by
 * hand and in bulk, which go to the season going on (in the display zone).
 */
final class SeasonsPanelTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private AchievementModel $best;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        config(['app.display_timezone' => 'Europe/Madrid']);
        $this->withoutStarterCatalogue();
        ParticipationActivitySource::reset();
        $this->registerSource(ParticipationActivitySource::class);
        $this->registerOnAdminPanel(resources: [AchievementResource::class]);
        Bus::fake([EvaluateAchievementsJob::class]);
        // 00:30 on 1 January 2027 in Madrid
        $this->travelTo('2026-12-31 23:30:00');

        $this->best = $this->achievement(['version' => 1, 'metric' => 'manual', 'window' => ['type' => 'season']], ['name' => 'Mejor partida del año']);
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(bulkGrants: true));
    }

    protected function tearDown(): void
    {
        ParticipationActivitySource::reset();

        parent::tearDown();
    }

    public function test_the_rule_builder_saves_seasons_and_streaks(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $this->registerInMemorySource();
        $this->app->forgetScopedInstances();
        $badge = ['icon' => 'flame', 'colour' => 'orange', 'points' => 0, 'rule_kind' => 'game_session'];

        Livewire::test(CreateAchievement::class)
            ->fillForm(['name' => 'Diez al año', 'rule_metric' => 'count', 'rule_window' => 'season', 'rule_threshold' => 10] + $badge)
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateAchievement::class)
            ->fillForm(['name' => 'Medio año seguido', 'rule_metric' => 'count', 'rule_window' => 'streak', 'rule_streak_months' => 6, 'rule_threshold' => 2] + $badge)
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateAchievement::class)
            ->fillForm(['name' => 'Trimestre del año', 'rule_metric' => 'count', 'rule_window' => 'streak', 'rule_streak_months' => 3, 'rule_streak_per_season' => true, 'rule_threshold' => 1] + $badge)
            ->call('create')
            ->assertHasNoFormErrors();

        $rules = AchievementModel::query()->whereIn('name', ['Diez al año', 'Medio año seguido', 'Trimestre del año'])->orderBy('name')->pluck('rule', 'name')->all();
        $this->assertSame(['type' => 'season'], $rules['Diez al año']['window']);
        $this->assertSame(['type' => 'streak', 'months' => 6], $rules['Medio año seguido']['window']);
        $this->assertSame(2, $rules['Medio año seguido']['threshold']);
        $this->assertSame(['type' => 'streak', 'months' => 3, 'per_season' => true], $rules['Trimestre del año']['window']);

        $streak = AchievementModel::query()->where('name', 'Medio año seguido')->sole();
        $this->assertSame('2 · Partidas · racha de 6 meses', AchievementPanelView::ruleSummary($streak));
        $this->assertSame('Solo a mano · por temporada', AchievementPanelView::ruleSummary($this->best));
    }

    public function test_a_streak_too_long_for_a_season_is_refused(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $this->registerInMemorySource();
        $this->app->forgetScopedInstances();

        Livewire::test(CreateAchievement::class)
            ->fillForm(['name' => 'Imposible', 'icon' => 'flame', 'colour' => 'orange', 'points' => 0, 'rule_metric' => 'count', 'rule_kind' => 'game_session', 'rule_window' => 'streak', 'rule_streak_months' => 14, 'rule_streak_per_season' => true, 'rule_threshold' => 1])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertFalse(AchievementModel::query()->where('name', 'Imposible')->exists());
    }

    public function test_a_grant_by_hand_goes_to_the_season_going_on_and_once_in_it(): void
    {
        $this->actingAs($this->granter());
        $ana = $this->account(self::ANA);
        AchievementGrantModel::query()->insert([
            'id' => '01a2051f-0000-7000-8000-000000000001', 'user_id' => $ana, 'achievement_id' => $this->best->id,
            'season' => '2026', 'season_key' => '2026', 'granted_at' => '2026-06-01 18:00:00', 'source' => 'manual',
            'actor_id' => null, 'silent' => true, 'created_at' => '2026-06-01 18:00:00', 'updated_at' => '2026-06-01 18:00:00',
        ]);

        // Last season's does not stop this season's
        $this->holders()
            ->callAction(TestAction::make('grantAchievement')->table(), data: ['user_id' => $ana, 'notify' => false])
            ->assertHasNoFormErrors()
            ->assertNotified(__('achievements::messages.grants.grant.done'));

        $this->assertSame(['2026', '2027'], AchievementGrantModel::query()->where('user_id', $ana)->orderBy('season_key')->pluck('season')->all());

        // This season's is held now: not offered again
        $this->holders()
            ->callAction(TestAction::make('grantAchievement')->table(), data: ['user_id' => $ana, 'notify' => false])
            ->assertHasFormErrors(['user_id']);
    }

    public function test_a_bulk_grant_goes_to_the_season_going_on(): void
    {
        $this->actingAs($this->granter());
        $this->account(self::ANA);
        ParticipationActivitySource::put('7', 'Jornadas 2027', [$this->activityFact(self::ANA, ActivityRole::Attendee)]);
        $this->ledgerSubject(ParticipationActivitySource::TYPE, '7', 'event', [$this->recordedFact(self::ANA, 'attendee')]);

        $this->holders()
            ->callAction(TestAction::make('bulkGrant')->table(), data: [
                'source_type' => ParticipationActivitySource::TYPE,
                'subject_id' => '7',
                'role' => ActivityRole::Attendee->value,
                'notice' => 'silent',
                'confirmation' => true,
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(['2027'], AchievementGrantModel::query()->pluck('season')->all());
    }

    public function test_with_seasons_switched_off_a_seasonal_achievement_is_not_granted_by_hand(): void
    {
        $granter = $this->granter();
        $always = $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Fundadora']);
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(seasonsAndStreaks: false));
        $this->app->forgetScopedInstances();

        $this->assertFalse(Gate::forUser($granter)->allows('grant', $this->best));
        $this->assertTrue(Gate::forUser($granter)->allows('revoke', $this->best));
        $this->assertTrue(Gate::forUser($granter)->allows('grant', $always));
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
        return Livewire::test(GrantsRelationManager::class, ['ownerRecord' => $this->best, 'pageClass' => EditAchievement::class]);
    }
}
