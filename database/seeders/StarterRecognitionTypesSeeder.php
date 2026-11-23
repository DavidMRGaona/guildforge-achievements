<?php

declare(strict_types=1);

namespace Modules\Achievements\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Achievements\Application\Services\StarterRecognitionTypesInterface;

/**
 * Runs once (the core remembers each seeder it ran, also on a site that updates the module): the
 * starter recognition types, which each site then edits.
 */
final class StarterRecognitionTypesSeeder extends Seeder
{
    public function run(): void
    {
        resolve(StarterRecognitionTypesInterface::class)->install();
    }
}
