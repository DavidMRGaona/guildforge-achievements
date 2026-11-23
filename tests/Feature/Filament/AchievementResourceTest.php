<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Filament;

use App\Application\Profiles\ProfileBadgeIcons;
use App\Domain\Profiles\ProfileBadgeColour;
use App\Filament\Actions\RowDetailsAction;
use App\Filament\Support\PanelNavigationGroup;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\Column;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Achievements\AchievementsServiceProvider;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\ValueObjects\MetricKinds;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Filament\Media\AchievementBadgeImageMediaSource;
use Modules\Achievements\Filament\Resources\AchievementResource;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\CreateAchievement;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\EditAchievement;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\ListAchievements;
use Modules\Achievements\Filament\Support\AchievementPanelView;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Policies\AchievementPolicy;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use RuntimeException;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;

final class AchievementResourceTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutStarterCatalogue();

        $this->registerInMemorySource();
        $this->registerOnAdminPanel(resources: [AchievementResource::class]);
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();

        parent::tearDown();
    }

    public function test_it_sits_in_the_people_group_with_its_slot(): void
    {
        $this->assertSame(PanelNavigationGroup::People->getLabel(), AchievementResource::getNavigationGroup());
        $this->assertSame(50, AchievementResource::getNavigationSort());
        $this->assertSame('Logros', AchievementResource::getNavigationLabel());
    }

    public function test_the_provider_registers_the_policy_and_the_media_source(): void
    {
        $provider = new AchievementsServiceProvider($this->app);

        $this->assertSame(AchievementPolicy::class, $provider->registerPolicies()[AchievementModel::class] ?? null);
        $this->assertSame([AchievementBadgeImageMediaSource::class], $provider->registerMediaSources());
    }

    public function test_viewing_needs_view_any_manage_or_grant_and_writing_needs_manage(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::VIEW_ANY));
        $this->assertTrue(AchievementResource::canViewAny());
        $this->assertFalse(AchievementResource::canCreate());

        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $this->assertTrue(AchievementResource::canViewAny());
        $this->assertTrue(AchievementResource::canCreate());

        // Granting needs seeing what there is to grant
        $this->actingAs($this->userWith('achievements:achievements.grant'));
        $this->assertTrue(AchievementResource::canViewAny());
        $this->assertFalse(AchievementResource::canCreate());

        $this->actingAs($this->userWith('achievements:achievements.configure'));
        $this->assertFalse(AchievementResource::canViewAny());
    }

    public function test_the_icon_and_colour_come_from_the_core_lists(): void
    {
        $this->assertSame(ProfileBadgeIcons::NAMES, array_values(array_intersect(ProfileBadgeIcons::NAMES, array_keys(AchievementResource::iconOptions()))));
        $this->assertCount(count(ProfileBadgeIcons::NAMES), AchievementResource::iconOptions());
        $this->assertSame(array_map(static fn (ProfileBadgeColour $colour): string => $colour->value, ProfileBadgeColour::cases()), array_keys(AchievementResource::colourOptions()));
    }

    public function test_creating_stores_the_rule_and_grants_silently_to_whoever_already_met_it(): void
    {
        $ana = $this->account(self::ANA);
        $this->ledgerSubject(InMemoryActivitySource::TYPE, '1', 'game_session', [$this->recordedFact($ana, 'director', system: 'pf2')]);
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        Livewire::test(CreateAchievement::class)
            ->fillForm([
                'name' => 'Primera partida dirigida',
                'description' => 'Dirige una partida',
                'icon' => 'crown',
                'colour' => 'violet',
                'points' => 20,
                'rule_metric' => 'count',
                'rule_kind' => 'game_session',
                'rule_role' => 'director',
                'rule_threshold' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $achievement = AchievementModel::query()->sole();
        $this->assertSame(['version' => 1, 'metric' => 'count', 'kind' => 'game_session', 'filters' => ['role' => 'director'], 'threshold' => 1, 'window' => ['type' => 'all_time']], $achievement->rule);
        $grant = DB::table('achievements_grants')->sole();
        $this->assertSame($ana, $grant->user_id);
        $this->assertTrue((bool) $grant->silent);
    }

    public function test_an_attribute_filter_keeps_the_label_of_its_value(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        Livewire::test(CreateAchievement::class)
            ->fillForm([
                'name' => 'Pathfinder',
                'icon' => 'dices',
                'colour' => 'red',
                'points' => 0,
                'rule_metric' => 'count',
                'rule_kind' => 'game_session',
                'rule_attribute_key' => 'game_system',
                'rule_attribute_value' => 'pf2',
                'rule_threshold' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rule = AchievementModel::query()->sole()->rule;
        $this->assertIsArray($rule);
        $this->assertSame(['key' => 'game_system', 'value' => 'pf2', 'label' => 'Pathfinder 2'], $rule['filters']['attribute']);
    }

    public function test_a_years_rule_and_a_manual_rule_are_built_too(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        foreach ([['rule_metric' => 'years', 'rule_basis' => 'account', 'rule_threshold' => 3], ['rule_metric' => 'manual']] as $i => $rule) {
            Livewire::test(CreateAchievement::class)
                ->fillForm(['name' => 'Logro '.$i, 'icon' => 'hourglass', 'colour' => 'teal', 'points' => 0] + $rule)
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $rules = AchievementModel::query()->orderBy('name')->pluck('rule')->all();
        $this->assertSame(['version' => 1, 'metric' => 'years', 'basis' => 'account', 'threshold' => 3], $rules[0]);
        $this->assertSame(['version' => 1, 'metric' => 'manual'], $rules[1]);
    }

    public function test_a_people_rule_is_built_and_summed_up_without_naming_anybody(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        Livewire::test(CreateAchievement::class)
            ->fillForm(['name' => 'Mesa abierta', 'icon' => 'handshake', 'colour' => 'violet', 'points' => 0, 'rule_metric' => 'people', 'rule_kind' => 'game_session', 'rule_role' => 'director', 'rule_with_role' => 'player', 'rule_threshold' => 20])
            ->assertFormFieldExists('rule_kind', static fn ($field): bool => array_diff(array_keys($field->getOptions()), ['game_session', 'campaign', 'tournament', 'event']) === [])
            ->assertFormFieldExists('rule_role', static fn ($field): bool => array_keys($field->getOptions()) === ['player', 'director'])
            ->assertFormFieldExists('rule_with_role', static fn ($field): bool => array_keys($field->getOptions()) === ['player', 'director'])
            ->assertFormFieldIsHidden('rule_attribute_key')
            ->assertFormFieldIsHidden('rule_placement_max')
            ->call('create')
            ->assertHasNoFormErrors();

        $people = AchievementModel::query()->where('name', 'Mesa abierta')->firstOrFail();
        $this->assertSame(['version' => 1, 'metric' => 'people', 'kind' => 'game_session', 'filters' => ['role' => 'director', 'with_role' => 'player'], 'threshold' => 20, 'window' => ['type' => 'all_time']], $people->rule);
        $this->assertSame('Personas distintas: 20 · Partidas · Dirección · con jugador', AchievementPanelView::ruleSummary($people));

        Livewire::test(EditAchievement::class, ['record' => $people->getKey()])
            ->assertFormSet(['rule_metric' => 'people', 'rule_kind' => 'game_session', 'rule_role' => 'director', 'rule_with_role' => 'player', 'rule_threshold' => 20]);
    }

    public function test_a_people_rule_of_a_kind_done_alone_is_refused(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        Livewire::test(CreateAchievement::class)
            ->fillForm(['name' => 'Foreros', 'icon' => 'users', 'colour' => 'sky', 'points' => 0, 'rule_metric' => 'people', 'rule_kind' => 'forum_post', 'rule_threshold' => 3])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame(0, AchievementModel::query()->count());
    }

    public function test_community_achievements_switched_off_say_so_in_the_table(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $people = $this->achievement(['version' => 1, 'metric' => 'people', 'kind' => 'game_session', 'threshold' => 10]);
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(community: false));
        $this->app->forgetScopedInstances();
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        Livewire::test(ListAchievements::class)
            ->assertTableColumnStateSet('status', 'community_off', $people)
            ->assertSee('Comunidad apagada');
    }

    public function test_a_distinct_rule_needs_its_attribute(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        Livewire::test(CreateAchievement::class)
            ->fillForm(['name' => 'Sistemas', 'icon' => 'compass', 'colour' => 'sky', 'points' => 0, 'rule_metric' => 'distinct', 'rule_kind' => 'game_session', 'rule_threshold' => 3])
            ->call('create')
            ->assertHasFormErrors(['rule_distinct_key' => 'required']);

        $this->assertSame(0, AchievementModel::query()->count());
    }

    public function test_only_kinds_with_a_source_are_offered_and_a_stored_one_is_kept(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $events = $this->achievement($this->countRule('event', 5));
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        // The kinds of every source offered here (the core's own and the test's), in the module's order
        $offered = array_values(array_filter(MetricKinds::ALL, fn (string $kind): bool => $this->app->make(ActivityMetricsInterface::class)->hasSource($kind)));
        $this->assertContains('game_session', $offered);
        $this->assertNotContains('event', $offered);

        Livewire::test(CreateAchievement::class)
            ->fillForm(['rule_kind' => 'event'])
            ->assertFormFieldExists('rule_kind', static fn ($field): bool => array_keys($field->getOptions()) === $offered);

        Livewire::test(EditAchievement::class, ['record' => $events->getKey()])
            ->assertFormSet(['rule_metric' => 'count', 'rule_kind' => 'event', 'rule_threshold' => 5])
            ->assertFormFieldExists('rule_kind', static fn ($field): bool => ($field->getOptions()['event'] ?? null) === 'Eventos (sin fuente)'
                && ($field->getOptions()['game_session'] ?? null) === 'Partidas'
                && count($field->getOptions()) === count($offered) + 1);
    }

    public function test_editing_keeps_the_rule_and_an_unreadable_one_must_be_written_again(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $broken = $this->achievement('{"version":7}');
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        Livewire::test(EditAchievement::class, ['record' => $broken->getKey()])
            ->assertFormSet(['rule_metric' => null])
            ->fillForm(['rule_metric' => 'count', 'rule_kind' => 'game_session', 'rule_threshold' => 2])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, $broken->fresh()?->rule['threshold'] ?? null);
    }

    public function test_the_table_says_why_an_achievement_is_not_evaluated(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $active = $this->achievement($this->countRule());
        $noSource = $this->achievement($this->countRule('tournament'));
        $manual = $this->achievement(['version' => 1, 'metric' => 'manual']);
        // Valid JSON (PostgreSQL refuses anything else in a json column), yet no rule this release reads
        $broken = $this->achievement('{"version":1,"metric":"count","kind":"game_session","threshold":0}');
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        Livewire::test(ListAchievements::class)
            ->assertTableColumnStateSet('status', 'evaluated', $active)
            ->assertTableColumnStateSet('status', 'no_source', $noSource)
            ->assertTableColumnStateSet('status', 'manual', $manual)
            ->assertTableColumnStateSet('status', 'invalid_rule', $broken)
            ->assertSee('Sin fuente');
    }

    public function test_whoever_only_views_sees_secret_achievements_masked_and_cannot_find_them(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $secret = $this->achievement($this->countRule(), ['name' => 'Dragón dormido', 'is_secret' => true]);
        $plain = $this->achievement($this->countRule(), ['name' => 'Dragón despierto']);

        $this->actingAs($this->userWith(AchievementPolicy::VIEW_ANY));
        Livewire::test(ListAchievements::class)
            ->assertCanSeeTableRecords([$secret, $plain])
            ->assertTableColumnFormattedStateSet('name', 'Logro secreto', $secret)
            ->assertTableColumnStateSet('rule_summary', '—', $secret)
            ->assertDontSee('Dragón dormido')
            ->searchTable('dormido')
            ->assertCanNotSeeTableRecords([$secret]);

        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        Livewire::test(ListAchievements::class)
            ->assertTableColumnFormattedStateSet('name', 'Dragón dormido', $secret)
            ->searchTable('dormido')
            ->assertCanSeeTableRecords([$secret])
            ->assertCanNotSeeTableRecords([$plain]);
    }

    public function test_sorting_never_shows_where_a_secret_name_falls(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        // «Logro secreto» sorts before both visible ones; its real name, after them
        $secret = $this->achievement($this->countRule(), ['name' => 'Zzz secreto', 'is_secret' => true]);
        $middle = $this->achievement($this->countRule(), ['name' => 'Mmm visible']);
        $last = $this->achievement($this->countRule(), ['name' => 'Ppp visible']);

        $this->actingAs($this->userWith(AchievementPolicy::VIEW_ANY));
        Livewire::test(ListAchievements::class)->assertCanSeeTableRecords([$secret, $middle, $last], inOrder: true);
        Livewire::test(ListAchievements::class)->sortTable('name', 'desc')->assertCanSeeTableRecords([$last, $middle, $secret], inOrder: true);

        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        Livewire::test(ListAchievements::class)->assertCanSeeTableRecords([$middle, $last, $secret], inOrder: true);
    }

    public function test_an_achievement_somebody_holds_cannot_be_deleted_only_switched_off(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $ana = $this->account(self::ANA);
        $achievement = $this->achievement($this->countRule());
        $this->app->make(AchievementGranterInterface::class)->grant(new NewGrant($ana, $achievement->id, GrantSource::Rule, silent: true));
        $manager = $this->userWith(AchievementPolicy::MANAGE);
        $this->actingAs($manager);

        $this->assertFalse($manager->can('delete', $achievement));
        Livewire::test(EditAchievement::class, ['record' => $achievement->getKey()])->assertActionHidden(DeleteAction::class);
        Livewire::test(ListAchievements::class)->assertTableActionHidden(DeleteAction::class, $achievement);

        $this->assertNotNull($achievement->fresh());
        $this->assertSame(1, DB::table('achievements_grants')->count());
    }

    public function test_an_achievement_nobody_holds_is_deleted_after_ticking_the_box(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $achievement = $this->achievement($this->countRule());
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        Livewire::test(EditAchievement::class, ['record' => $achievement->getKey()])
            ->callAction(DeleteAction::class, ['confirmation' => false])
            ->assertHasActionErrors(['confirmation']);
        $this->assertNotNull($achievement->fresh());

        Livewire::test(EditAchievement::class, ['record' => $achievement->getKey()])
            ->callAction(DeleteAction::class, ['confirmation' => true]);

        $this->assertNull($achievement->fresh());
    }

    public function test_an_achievement_granted_while_the_dialog_is_open_is_not_deleted(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $ana = $this->account(self::ANA);
        $achievement = $this->achievement($this->countRule());
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        // Granted by another process after the permission was checked, right before the DELETE
        AchievementModel::deleting(function (AchievementModel $record) use ($ana): void {
            $this->app->make(AchievementGranterInterface::class)->grant(new NewGrant($ana, $record->id, GrantSource::Rule, silent: true));
        });

        Livewire::test(EditAchievement::class, ['record' => $achievement->getKey()])
            ->callAction(DeleteAction::class, ['confirmation' => true])
            ->assertNotified('Alguien lo acaba de conseguir; desactívalo en su lugar.');
        // The grant made here rolls back with the deletion; another process's would stay
        $this->assertNotNull($achievement->fresh());
    }

    public function test_any_other_database_error_while_deleting_is_not_taken_for_a_grant(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $achievement = $this->achievement($this->countRule());
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        AchievementModel::deleting(static function (): void {
            throw new QueryException('pgsql', 'delete from achievements_catalogue', [], new RuntimeException('The database is away.'));
        });

        $this->expectException(QueryException::class);

        Livewire::test(EditAchievement::class, ['record' => $achievement->getKey()])
            ->callAction(DeleteAction::class, ['confirmation' => true]);
    }

    public function test_saving_again_keeps_the_label_of_a_value_the_sources_no_longer_offer(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $achievement = $this->achievement($this->countRule(extra: ['filters' => ['attribute' => ['key' => 'game_system', 'value' => 'gone', 'label' => 'Sistema retirado']]]));
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        Livewire::test(EditAchievement::class, ['record' => $achievement->getKey()])
            ->fillForm(['name' => 'Otro nombre'])
            ->call('save')
            ->assertHasNoFormErrors();

        $rule = $achievement->fresh()?->rule;
        $this->assertIsArray($rule);
        $this->assertSame(['key' => 'game_system', 'value' => 'gone', 'label' => 'Sistema retirado'], $rule['filters']['attribute']);
    }

    public function test_the_table_fits_narrow_screens(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $page = Livewire::test(ListAchievements::class)->instance();
        $this->assertInstanceOf(ListAchievements::class, $page);
        $table = $page->getTable();

        $actions = $table->getRecordActions();
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(ActionGroup::class, $actions[0]);
        $first = $actions[0]->getActions()[0];
        $this->assertInstanceOf(Action::class, $first);
        $this->assertSame(RowDetailsAction::make()->getName(), $first->getName());

        $visibleFrom = array_map(static fn (Column $column): ?string => $column->getVisibleFrom(), $table->getColumns());
        $this->assertSame(
            ['name' => null, 'status' => null, 'holders_count' => 'sm', 'rule_summary' => 'md', 'points' => 'xl', 'is_secret' => 'xl'],
            $visibleFrom,
        );
    }

    public function test_the_media_source_claims_the_badge_folder_and_masks_secret_titles(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $this->achievement($this->countRule(), ['name' => 'Dragón dormido', 'is_secret' => true, 'image_public_id' => 'achievements/badges/2026/11/a.png']);
        $this->achievement($this->countRule(), ['name' => 'Visible', 'image_public_id' => 'achievements/badges/2026/11/b.png']);
        $source = $this->app->make(AchievementBadgeImageMediaSource::class);

        $this->assertSame('achievements.badge-image', $source->type());
        $this->assertSame(['achievements/badges/'], $source->folders());
        $this->assertSame([], $source->excludedSubfolders());
        $this->assertFalse($source->hidden());

        $titles = array_map(static fn ($usage): string => $usage->title, iterator_to_array($source->usages(), false));
        sort($titles);
        $this->assertSame(['Logro secreto', 'Visible'], $titles);
    }
}
