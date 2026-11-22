<?php

declare(strict_types=1);

namespace Modules\Achievements\Console\Commands;

use Illuminate\Console\Command;
use Modules\Achievements\Application\Services\StarterCatalogueInterface;

/**
 * Creates the starter catalogue, or what is missing from it. Run once when the module is installed
 * (its seeder does), and again only to bring back a starter achievement that was deleted: the ones
 * in the catalogue, edited or not, are left as they are.
 */
final class InstallStarterCatalogueCommand extends Command
{
    /** @var string */
    protected $signature = 'achievements:install-starter-catalogue';

    /** @var string */
    protected $description = 'Create the starter achievements that are missing from the catalogue';

    public function handle(StarterCatalogueInterface $catalogue): int
    {
        $created = $catalogue->install();

        $this->components->info((string) __('achievements::messages.starter.installed', [
            'created' => count($created),
            'kept' => count($catalogue->keys()) - count($created),
        ]));

        return self::SUCCESS;
    }
}
