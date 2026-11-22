<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Notices;

use Modules\Achievements\Notifications\AchievementList;
use Tests\Support\Modules\ModuleTestCase;

final class AchievementListTest extends ModuleTestCase
{
    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('es');
    }

    public function test_it_names_a_few_and_counts_the_rest_and_the_secrets(): void
    {
        $this->assertSame('', AchievementList::sentence([]));
        $this->assertSame('«A»', AchievementList::sentence(['A']));
        $this->assertSame('«A» y «B»', AchievementList::sentence(['A', 'B']));
        $this->assertSame('«A», «B» y «C»', AchievementList::sentence(['A', 'B', 'C']));
        $this->assertSame('«A», «B», «C» y 1 más', AchievementList::sentence(['A', 'B', 'C', 'D']));
        $this->assertSame('«A» y un logro secreto', AchievementList::sentence(['A'], 1));
        $this->assertSame('«A», «B», «C», 2 más y 3 logros secretos', AchievementList::sentence(['A', 'B', 'C', 'D', 'E'], 3));
        $this->assertSame('2 logros secretos', AchievementList::sentence([], 2));
    }
}
