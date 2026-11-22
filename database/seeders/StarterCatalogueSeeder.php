<?php

declare(strict_types=1);

namespace Modules\Achievements\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Achievements\Application\Services\StarterCatalogueInterface;

/**
 * Runs once, when the module is first installed: the starter catalogue, which each site then edits.
 * The data lives in StarterCatalogue so achievements:install-starter-catalogue does the same.
 */
final class StarterCatalogueSeeder extends Seeder
{
    /**
     * The core calls run() without arguments, so the service is resolved here.
     */
    public function run(): void
    {
        resolve(StarterCatalogueInterface::class)->install();
    }
}
