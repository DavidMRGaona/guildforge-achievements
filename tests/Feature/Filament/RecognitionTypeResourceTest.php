<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Filament;

use App\Filament\Actions\RowDetailsAction;
use App\Filament\Support\PanelNavigationGroup;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\Column;
use Livewire\Livewire;
use Modules\Achievements\AchievementsServiceProvider;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Filament\Resources\AchievementResource;
use Modules\Achievements\Filament\Resources\RecognitionTypeResource;
use Modules\Achievements\Filament\Resources\RecognitionTypeResource\Pages\CreateRecognitionType;
use Modules\Achievements\Filament\Resources\RecognitionTypeResource\Pages\EditRecognitionType;
use Modules\Achievements\Filament\Resources\RecognitionTypeResource\Pages\ListRecognitionTypes;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\RecognitionTypeModel;
use Modules\Achievements\Policies\AchievementPolicy;
use Modules\Achievements\Policies\RecognitionTypePolicy;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Tests\Support\Modules\ModuleTestCase;

final class RecognitionTypeResourceTest extends ModuleTestCase
{
    use BuildsAchievements;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->switchRecognitions();
        $this->registerOnAdminPanel(resources: [AchievementResource::class, RecognitionTypeResource::class]);
    }

    public function test_it_sits_under_achievements_in_the_people_group(): void
    {
        $this->assertSame(PanelNavigationGroup::People->getLabel(), RecognitionTypeResource::getNavigationGroup());
        $this->assertSame(AchievementResource::getNavigationLabel(), RecognitionTypeResource::getNavigationParentItem());
        $this->assertSame('Tipos de reconocimiento', RecognitionTypeResource::getNavigationLabel());
    }

    public function test_the_provider_registers_its_policy(): void
    {
        $this->assertSame(RecognitionTypePolicy::class, new AchievementsServiceProvider($this->app)->registerPolicies()[RecognitionTypeModel::class] ?? null);
    }

    public function test_viewing_needs_view_any_or_manage_and_writing_needs_manage(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::VIEW_ANY));
        $this->assertTrue(RecognitionTypeResource::canAccess());
        $this->assertFalse(RecognitionTypeResource::canCreate());

        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $this->assertTrue(RecognitionTypeResource::canCreate());

        $this->actingAs($this->userWith(AchievementPolicy::GRANT));
        $this->assertFalse(RecognitionTypeResource::canAccess());
    }

    public function test_it_is_gone_with_the_switch_off(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $this->switchRecognitions(false);

        $this->assertFalse(RecognitionTypeResource::canAccess());
        $this->get(RecognitionTypeResource::getUrl('index'))->assertForbidden();
    }

    public function test_creating_a_type_with_a_name_an_icon_and_a_colour(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        Livewire::test(CreateRecognitionType::class)
            ->fillForm(['name' => 'Gran estrategia', 'icon' => 'target', 'colour' => 'teal', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $type = RecognitionTypeModel::query()->where('name', 'Gran estrategia')->sole();
        $this->assertSame(['target', 'teal', true, null], [$type->icon, $type->colour, $type->is_active, $type->starter_key]);
    }

    public function test_icon_and_colour_come_from_the_core_lists_and_the_name_is_short(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        Livewire::test(CreateRecognitionType::class)
            ->fillForm(['name' => str_repeat('a', 41), 'icon' => 'skull', 'colour' => 'black'])
            ->call('create')
            ->assertHasFormErrors(['name', 'icon', 'colour']);
    }

    public function test_the_name_is_kept_to_one_clean_line(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));

        Livewire::test(CreateRecognitionType::class)
            ->fillForm(['name' => "  Gran\u{202E} ambiente\n de mesa ", 'icon' => 'flame', 'colour' => 'amber'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(RecognitionTypeModel::query()->where('name', 'Gran ambiente de mesa')->exists());
    }

    public function test_editing_and_switching_off_a_type(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $type = RecognitionTypeModel::query()->where('starter_key', 'great_atmosphere')->sole();

        Livewire::test(EditRecognitionType::class, ['record' => $type->id])
            ->fillForm(['name' => 'Buen rollo', 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $type->refresh();
        $this->assertSame(['Buen rollo', false], [$type->name, $type->is_active]);
    }

    public function test_the_table_fits_narrow_screens(): void
    {
        $this->actingAs($this->userWith(AchievementPolicy::MANAGE));
        $page = Livewire::test(ListRecognitionTypes::class)->instance();
        $this->assertInstanceOf(ListRecognitionTypes::class, $page);
        $table = $page->getTable();

        $actions = $table->getRecordActions();
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(ActionGroup::class, $actions[0]);
        $first = $actions[0]->getActions()[0];
        $this->assertInstanceOf(Action::class, $first);
        $this->assertSame(RowDetailsAction::NAME, $first->getName());

        $visibleFrom = array_map(static fn (Column $column): ?string => $column->getVisibleFrom(), $table->getColumns());
        $this->assertSame(['name' => null, 'is_active' => null, 'icon' => 'sm', 'colour' => 'sm'], $visibleFrom);
    }

    private function switchRecognitions(bool $on = true): void
    {
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(recognitions: $on));
        $this->app->forgetScopedInstances();
    }
}
