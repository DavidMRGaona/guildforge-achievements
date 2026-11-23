<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Seasons;

use App\Application\Services\SettingsServiceInterface;
use App\Domain\Activity\ActivityRole;
use App\Domain\Events\UserAnonymized;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Time\StorageTimezoneResolver;
use DateTimeImmutable;
use DateTimeZone;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\DTOs\BulkGrantRequestDTO;
use Modules\Achievements\Application\Enums\BulkGrantOutcome;
use Modules\Achievements\Application\Services\AchievementEvaluatorInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\BulkGrantInterface;
use Modules\Achievements\Domain\Events\AchievementGranted;
use Modules\Achievements\Domain\ValueObjects\RecordedFact;
use Modules\Achievements\Filament\Resources\AchievementResource;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\EditAchievement;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\ListAchievements;
use Modules\Achievements\Filament\Resources\AchievementResource\RelationManagers\GrantsRelationManager;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementGrantModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Policies\AchievementPolicy;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Modules\Achievements\Tests\Support\ParticipationActivitySource;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;

/**
 * What the review of seasons and streaks asked for: dates stored in Madrid, a December game that
 * arrives in January, New Year's Eve for grants by hand and in bulk, an anonymized account, the
 * window locked once somebody holds an achievement and holders counted as people.
 */
final class SeasonsReviewTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use BuildsShowcase;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Stored in Madrid, as sites with data from before UTC storage are
        $this->useMadridStorage();
        config(['app.display_timezone' => 'Europe/Madrid']);
        $this->withoutStarterCatalogue();
        $this->registerInMemorySource();
        ParticipationActivitySource::reset();
        $this->registerSource(ParticipationActivitySource::class);
        $this->registerOnAdminPanel(resources: [AchievementResource::class]);
        Bus::fake([EvaluateAchievementsJob::class]);
        Event::fake([AchievementGranted::class]);
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(bulkGrants: true));
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();
        ParticipationActivitySource::reset();

        parent::tearDown();
    }

    public function test_with_dates_stored_in_madrid_the_season_still_follows_the_display_zone(): void
    {
        $this->assertSame('Europe/Madrid', date_default_timezone_get());
        $this->travelTo(new DateTimeImmutable('2027-02-01 12:00:00', new DateTimeZone('UTC')));
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule(threshold: 2, extra: ['window' => ['type' => 'season']]));
        // 23:30 and 23:45 on 31 December in Madrid: both of 2026, whatever zone stores them
        $this->playedAt($ana, 's1', '2026-12-31 22:30:00');
        $this->playedAt($ana, 's2', '2026-12-31 22:45:00');
        // 00:30 on 1 January
        $this->playedAt($ana, 's3', '2026-12-31 23:30:00');

        $this->evaluator()->evaluate([$ana], silent: true);

        $this->assertSame(['2026'], $this->seasonsOf($ana));
    }

    public function test_a_december_game_recorded_in_january_counts_for_last_season_and_is_announced(): void
    {
        $this->travelTo(new DateTimeImmutable('2027-01-05 12:00:00', new DateTimeZone('UTC')));
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule(extra: ['window' => ['type' => 'season']]));
        $this->playedAt($ana, 's1', '2026-12-28 18:00:00');

        $result = $this->evaluator()->evaluate([$ana], silent: false);

        $this->assertSame('2026', $result->granted[0]->season);
        // Played a week ago: still news
        $this->assertFalse($result->granted[0]->silent);
    }

    public function test_a_grant_by_hand_on_new_years_eve_goes_to_the_season_of_the_display_zone(): void
    {
        $best = $this->achievement(['version' => 1, 'metric' => 'manual', 'window' => ['type' => 'season']], ['name' => 'La partida del año']);
        $this->actingAs($this->granter());
        $ana = $this->account(self::ANA);
        $luis = $this->account(self::MARTA);

        // 23:59:59 in Madrid
        $this->travelTo(new DateTimeImmutable('2026-12-31 22:59:59', new DateTimeZone('UTC')));
        $this->holders($best)->callAction(TestAction::make('grantAchievement')->table(), data: ['user_id' => $ana, 'notify' => false])->assertHasNoFormErrors();

        // 00:00:01 on 1 January in Madrid: the default season is the new one
        $this->travelTo(new DateTimeImmutable('2026-12-31 23:00:01', new DateTimeZone('UTC')));
        $this->holders($best)->callAction(TestAction::make('grantAchievement')->table(), data: ['user_id' => $luis, 'notify' => false])->assertHasNoFormErrors();

        $this->assertSame(['2026'], $this->seasonsOf($ana));
        $this->assertSame(['2027'], $this->seasonsOf($luis));
    }

    public function test_a_grant_by_hand_may_go_to_last_season_and_never_to_another(): void
    {
        $best = $this->achievement(['version' => 1, 'metric' => 'manual', 'window' => ['type' => 'season']], ['name' => 'La partida del año']);
        $this->travelTo(new DateTimeImmutable('2027-01-03 12:00:00', new DateTimeZone('UTC')));
        $this->actingAs($this->granter());
        $ana = $this->account(self::ANA);

        $this->holders($best)
            ->callAction(TestAction::make('grantAchievement')->table(), data: ['user_id' => $ana, 'season' => '2025', 'notify' => false])
            ->assertHasFormErrors(['season']);
        $this->assertSame([], $this->seasonsOf($ana));

        $this->holders($best)
            ->callAction(TestAction::make('grantAchievement')->table(), data: ['user_id' => $ana, 'season' => '2026', 'notify' => false])
            ->assertHasNoFormErrors();
        $this->assertSame(['2026'], $this->seasonsOf($ana));

        // Last season held: this one is still open to it
        $this->holders($best)
            ->callAction(TestAction::make('grantAchievement')->table(), data: ['user_id' => $ana, 'notify' => false])
            ->assertHasNoFormErrors();
        $this->assertSame(['2026', '2027'], $this->seasonsOf($ana));
    }

    public function test_a_bulk_grant_takes_the_chosen_season_for_everybody_and_checks_it(): void
    {
        $best = $this->achievement(['version' => 1, 'metric' => 'manual', 'window' => ['type' => 'season']], ['name' => 'Jornadas del año']);
        $this->travelTo(new DateTimeImmutable('2027-01-03 12:00:00', new DateTimeZone('UTC')));
        $granter = $this->granter();
        $this->jornadas([self::ANA, self::MARTA]);
        $bulk = $this->app->make(BulkGrantInterface::class);

        $wrong = $bulk->preview(new BulkGrantRequestDTO(ParticipationActivitySource::TYPE, '7', ActivityRole::Attendee, (string) $best->id, '2020'), $granter);
        $this->assertSame(BulkGrantOutcome::SeasonUnavailable, $wrong->outcome);

        $result = $bulk->grant(new BulkGrantRequestDTO(ParticipationActivitySource::TYPE, '7', ActivityRole::Attendee, (string) $best->id, '2026'), true, null, $granter);

        $this->assertSame(2, $result->granted);
        $this->assertSame(['2026'], AchievementGrantModel::query()->pluck('season')->unique()->values()->all());

        // Already held in 2026; the default season, 2027, is still open
        $this->assertSame(2, $bulk->preview(new BulkGrantRequestDTO(ParticipationActivitySource::TYPE, '7', ActivityRole::Attendee, (string) $best->id, '2026'), $granter)->alreadyHeld);
        $this->assertSame(0, $bulk->preview(new BulkGrantRequestDTO(ParticipationActivitySource::TYPE, '7', ActivityRole::Attendee, (string) $best->id), $granter)->alreadyHeld);
    }

    public function test_the_bulk_dialog_says_which_season_before_confirming(): void
    {
        $best = $this->achievement(['version' => 1, 'metric' => 'manual', 'window' => ['type' => 'season']], ['name' => 'Jornadas del año']);
        $this->travelTo(new DateTimeImmutable('2027-01-03 12:00:00', new DateTimeZone('UTC')));
        $this->actingAs($this->granter());
        $this->jornadas([self::ANA]);

        $this->holders($best)
            ->mountAction(TestAction::make('bulkGrant')->table())
            ->fillForm(['source_type' => ParticipationActivitySource::TYPE, 'subject_id' => '7', 'role' => ActivityRole::Attendee->value, 'season' => '2026'])
            ->assertMountedActionModalSee('Para la temporada 2026.');
    }

    public function test_a_bulk_grant_refuses_seasons_while_they_are_off(): void
    {
        $best = $this->achievement(['version' => 1, 'metric' => 'manual', 'window' => ['type' => 'season']], ['name' => 'Jornadas del año']);
        $this->jornadas([self::ANA]);
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(seasonsAndStreaks: false, bulkGrants: true));
        $this->app->forgetScopedInstances();

        $result = $this->app->make(BulkGrantInterface::class)->grant(new BulkGrantRequestDTO(ParticipationActivitySource::TYPE, '7', ActivityRole::Attendee, (string) $best->id), true, null, $this->granter());

        $this->assertSame(BulkGrantOutcome::SeasonUnavailable, $result->outcome);
        $this->assertSame(0, AchievementGrantModel::query()->count());
    }

    public function test_anonymizing_takes_every_season_and_keeps_the_history_without_the_account(): void
    {
        $this->travelTo(new DateTimeImmutable('2027-03-01 12:00:00', new DateTimeZone('UTC')));
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule(extra: ['window' => ['type' => 'season']]));
        $this->playedAt($ana, 's1', '2026-04-01 18:00:00');
        $this->playedAt($ana, 's2', '2027-02-01 18:00:00');
        $this->evaluator()->evaluate([$ana], silent: true);
        $this->assertSame(['2026', '2027'], $this->seasonsOf($ana));

        UserModel::query()->whereKey($ana)->update(['anonymized_at' => now()]);
        event(new UserAnonymized($ana));

        $this->assertSame(0, AchievementGrantModel::query()->where('user_id', $ana)->count());
        $this->assertSame(0, DB::table('achievements_grant_log')->where('user_id', $ana)->count());
        $this->assertEqualsCanonicalizing(['2026', '2027'], DB::table('achievements_grant_log')->whereNull('user_id')->pluck('season_key')->all());
        // And nothing comes back
        $this->evaluator()->evaluate([$ana], silent: true);
        $this->assertSame(0, AchievementGrantModel::query()->where('user_id', $ana)->count());
    }

    public function test_the_window_cannot_change_once_somebody_holds_the_achievement(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $streak = $this->achievement($this->countRule(extra: ['window' => ['type' => 'streak', 'months' => 3]]), ['name' => 'Tres meses']);
        $free = $this->achievement($this->countRule(extra: ['window' => ['type' => 'streak', 'months' => 3]]), ['name' => 'Tres meses más']);
        $this->grantAt($this->person('Ana'), (string) $streak->id);

        Livewire::test(EditAchievement::class, ['record' => $streak->getKey()])
            ->assertFormFieldIsDisabled('rule_window')
            ->assertFormFieldIsDisabled('rule_streak_per_season')
            ->fillForm(['rule_window' => 'season'])
            ->call('save')
            ->assertHasFormErrors(['rule_window']);
        $this->assertSame(['type' => 'streak', 'months' => 3], AchievementModel::query()->findOrFail($streak->id)->rule['window']);

        // The months may change, as a threshold may
        Livewire::test(EditAchievement::class, ['record' => $streak->getKey()])
            ->fillForm(['rule_streak_months' => 4])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['type' => 'streak', 'months' => 4], AchievementModel::query()->findOrFail($streak->id)->rule['window']);

        // Nobody holds the other one: it may change
        Livewire::test(EditAchievement::class, ['record' => $free->getKey()])
            ->assertFormFieldIsEnabled('rule_window')
            ->fillForm(['rule_window' => 'season'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['type' => 'season'], AchievementModel::query()->findOrFail($free->id)->rule['window']);
    }

    public function test_a_metric_without_streaks_takes_the_streak_off(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $streak = $this->achievement($this->countRule(extra: ['window' => ['type' => 'streak', 'months' => 3]]), ['name' => 'Tres meses']);

        Livewire::test(EditAchievement::class, ['record' => $streak->getKey()])
            ->fillForm(['rule_metric' => 'manual'])
            ->assertFormSet(['rule_window' => 'all_time']);
    }

    public function test_the_catalogue_counts_people_not_grants(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $yearly = $this->achievement($this->countRule(extra: ['window' => ['type' => 'season']]), ['name' => 'Cada año']);
        $ana = $this->person('Ana');
        $this->grantAt($ana, (string) $yearly->id, '2025-06-01 18:00:00', '2025');
        $this->grantAt($ana, (string) $yearly->id, '2026-06-01 18:00:00', '2026');
        $this->grantAt($this->person('Luis'), (string) $yearly->id, '2026-06-01 18:00:00', '2026');

        Livewire::test(ListAchievements::class)->assertTableColumnStateSet('holders_count', 2, $yearly);
    }

    public function test_the_owner_sees_a_secret_of_last_season_by_its_name_and_newest_season_first(): void
    {
        $this->travelTo(new DateTimeImmutable('2027-03-01 12:00:00', new DateTimeZone('UTC')));
        $secret = $this->achievement($this->countRule(threshold: 5, extra: ['window' => ['type' => 'season']]), ['name' => 'Cinco al año', 'is_secret' => true]);
        $ana = $this->person('Ana');
        // The past season was granted after the newer one (a recalculation)
        $this->grantAt($ana, (string) $secret->id, '2026-06-01 18:00:00', '2026');
        $this->grantAt($ana, (string) $secret->id, '2027-01-02 18:00:00', '2025');
        $this->factAt($ana, '2027-02-01 18:00:00');

        // The night passes: others see what the nightly photo holds
        $this->refreshRarity();
        $own = $this->achievementsSection($this->render($ana, $ana))?->props;

        $this->assertNotNull($own);
        $this->assertSame(['Cinco al año'], array_column($own['locked'], 'name'));
        $this->assertFalse($own['locked'][0]['masked']);
        $this->assertSame(['value' => 1, 'threshold' => 5, 'inMonths' => false], $own['locked'][0]['progress']);
        $this->assertSame(['2026', '2025'], $own['earned'][0]['seasons']);
        $this->assertSame('2026-06-01T18:00:00+02:00', $own['earned'][0]['grantedAt']);

        // Somebody else: only counted, never a season
        $others = $this->achievementsSection($this->render($ana, $this->person('Luis')))?->props;
        $this->assertNotNull($others);
        $this->assertSame([], $others['earned']);
        $this->assertSame(1, $others['secretCount']);
    }

    public function test_a_manual_one_per_season_says_its_season_among_what_is_left(): void
    {
        $this->travelTo(new DateTimeImmutable('2027-03-01 12:00:00', new DateTimeZone('UTC')));
        $this->achievement(['version' => 1, 'metric' => 'manual', 'window' => ['type' => 'season']], ['name' => 'La partida del año']);
        $ana = $this->person('Ana');

        $own = $this->achievementsSection($this->render($ana, $ana))?->props;

        $this->assertNotNull($own);
        $this->assertTrue($own['locked'][0]['manual']);
        $this->assertSame('2027', $own['locked'][0]['season']);
    }

    private function useMadridStorage(): void
    {
        $now = now()->toDateTimeString();

        DB::table('settings')->updateOrInsert(
            ['key' => StorageTimezoneResolver::SETTING_KEY],
            ['id' => (string) Str::uuid(), 'value' => 'Europe/Madrid', 'created_at' => $now, 'updated_at' => $now],
        );

        (new StorageTimezoneResolver)->apply($this->app);
        app(SettingsServiceInterface::class)->clearCache();
    }

    private function playedAt(string $userId, string $subject, string $utc): void
    {
        $this->ledgerSubject(InMemoryActivitySource::TYPE, $subject, 'game_session', [
            new RecordedFact($userId, 'player', new DateTimeImmutable($utc, new DateTimeZone('UTC')), []),
        ]);
    }

    /**
     * @param  list<string>  $attendees
     */
    private function jornadas(array $attendees): void
    {
        $facts = [];
        $recorded = [];

        foreach ($attendees as $id) {
            $this->account($id);
            $facts[] = $this->activityFact($id, ActivityRole::Attendee);
            $recorded[] = $this->recordedFact($id, 'attendee');
        }

        ParticipationActivitySource::put('7', 'Jornadas', $facts);
        $this->ledgerSubject(ParticipationActivitySource::TYPE, '7', 'event', $recorded);
    }

    /**
     * @return list<string>
     */
    private function seasonsOf(string $userId): array
    {
        $seasons = AchievementGrantModel::query()->where('user_id', $userId)->pluck('season_key')->map(strval(...))->all();
        sort($seasons);

        return $seasons;
    }

    private function granter(): UserModel
    {
        return $this->userWith(AchievementPolicy::GRANT, 'users.view_any', 'users.view');
    }

    /**
     * @return Testable<GrantsRelationManager>
     */
    private function holders(AchievementModel $achievement): Testable
    {
        return Livewire::test(GrantsRelationManager::class, ['ownerRecord' => $achievement, 'pageClass' => EditAchievement::class]);
    }

    private function evaluator(): AchievementEvaluatorInterface
    {
        $this->app->forgetScopedInstances();

        return $this->app->make(AchievementEvaluatorInterface::class);
    }
}
