<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Recognitions;

use Modules\Achievements\Application\Services\StarterRecognitionTypesInterface;
use Modules\Achievements\Database\Seeders\StarterRecognitionTypesSeeder;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\RecognitionTypeModel;
use Tests\Support\Modules\ModuleTestCase;

final class StarterRecognitionTypesTest extends ModuleTestCase
{
    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    public function test_enabling_the_module_creates_the_four_starter_types(): void
    {
        $types = RecognitionTypeModel::query()->orderBy('starter_key')->get();

        $this->assertSame(
            [
                ['good_companion', 'Buen compañero', 'handshake', 'sky'],
                ['great_atmosphere', 'Gran ambiente', 'flame', 'amber'],
                ['great_storytelling', 'Gran narración', 'scroll-text', 'violet'],
                ['helps_newcomers', 'Ayuda a quien empieza', 'sprout', 'green'],
            ],
            $types->map(static fn (RecognitionTypeModel $type): array => [$type->starter_key, $type->name, $type->icon, $type->colour])->all(),
        );
        $this->assertTrue($types->every(static fn (RecognitionTypeModel $type): bool => $type->is_active));
    }

    public function test_installing_again_adds_only_what_is_missing_and_never_touches_an_edited_type(): void
    {
        RecognitionTypeModel::query()->where('starter_key', 'great_atmosphere')->delete();
        RecognitionTypeModel::query()->where('starter_key', 'great_storytelling')->update(['name' => 'Narración de leyenda']);

        $created = $this->app->make(StarterRecognitionTypesInterface::class)->install();

        $this->assertSame(['great_atmosphere'], $created);
        $this->assertSame(4, RecognitionTypeModel::query()->count());
        $this->assertSame('Narración de leyenda', RecognitionTypeModel::query()->where('starter_key', 'great_storytelling')->value('name'));
    }

    public function test_the_seeder_installs_them(): void
    {
        RecognitionTypeModel::query()->delete();

        new StarterRecognitionTypesSeeder()->run();

        $this->assertSame(4, RecognitionTypeModel::query()->count());
    }
}
