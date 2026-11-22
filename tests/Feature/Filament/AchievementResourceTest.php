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
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Achievements\AchievementsServiceProvider;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Filament\Resources\AchievementResource;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\CreateAchievement;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\EditAchievement;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\ListAchievements;
use Modules\Achievements\Infrastructure\Media\AchievementBadgeImageMediaSource;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Policies\AchievementPolicy;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
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

        $this->assertSame([AchievementModel::class => AchievementPolicy::class], $provider->registerPolicies());
        $this->assertSame([AchievementBadgeImageMediaSource::class], $provider->registerMediaSources());
    }

    public function test_viewing_needs_view_any_or_manage_and_writing_needs_manage(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::VIEW_ANY));
        $this->assertTrue(AchievementResource::canViewAny());
        $this->assertFalse(AchievementResource::canCreate());

        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $this->assertTrue(AchievementResource::canViewAny());
        $this->assertTrue(AchievementResource::canCreate());

        $this->actingAs($this->userWith('achievements:achievements.grant', 'achievements:achievements.configure'));
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

        Livewire::test(CreateAchievement::class)
            ->fillForm(['rule_kind' => 'event'])
            ->assertFormFieldExists('rule_kind', static fn ($field): bool => array_keys($field->getOptions()) === ['game_session']);

        Livewire::test(EditAchievement::class, ['record' => $events->getKey()])
            ->assertFormSet(['rule_metric' => 'count', 'rule_kind' => 'event', 'rule_threshold' => 5])
            ->assertFormFieldExists('rule_kind', static fn ($field): bool => $field->getOptions() === ['game_session' => 'Partidas', 'event' => 'Eventos (sin fuente)']);
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
        $broken = $this->achievement('nope');
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

    public function test_deleting_asks_for_the_box_and_deletes_the_grants(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $ana = $this->account(self::ANA);
        $achievement = $this->achievement($this->countRule());
        $this->app->make(AchievementGranterInterface::class)->grant(new NewGrant($ana, $achievement->id, GrantSource::Rule, silent: true));
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        Livewire::test(EditAchievement::class, ['record' => $achievement->getKey()])
            ->callAction(DeleteAction::class, ['confirmation' => false])
            ->assertHasActionErrors(['confirmation']);
        $this->assertNotNull($achievement->fresh());

        Livewire::test(EditAchievement::class, ['record' => $achievement->getKey()])
            ->callAction(DeleteAction::class, ['confirmation' => true]);

        $this->assertNull($achievement->fresh());
        $this->assertSame(0, DB::table('achievements_grants')->count());
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
            ['name' => null, 'status' => null, 'grants_count' => 'sm', 'rule_summary' => 'md', 'points' => 'xl', 'is_secret' => 'xl'],
            $visibleFrom,
        );
    }

    public function test_the_media_source_claims_the_badge_folder_and_hides_secret_names(): void
    {
        Bus::fake([EvaluateAchievementsJob::class]);
        $this->achievement($this->countRule(), ['name' => 'Dragón dormido', 'is_secret' => true, 'image_public_id' => 'achievements/badges/2026/11/a.png']);
        $source = $this->app->make(AchievementBadgeImageMediaSource::class);

        $this->assertSame('achievements.badge-image', $source->type());
        $this->assertSame(['achievements/badges/'], $source->folders());
        $usages = iterator_to_array($source->usages(), false);
        $this->assertCount(1, $usages);
        $this->assertSame('Logro secreto', $usages[0]->title);
    }
}
