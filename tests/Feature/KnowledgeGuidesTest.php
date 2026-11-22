<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature;

use Tests\Support\Modules\ModuleTestCase;

final class KnowledgeGuidesTest extends ModuleTestCase
{
    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    public function test_the_module_guides_are_valid(): void
    {
        $this->assertModuleKnowledgeGuidesAreValid('achievements');
    }
}
