<?php

declare(strict_types=1);

namespace Modules\Achievements\Console\Commands;

use Illuminate\Console\Command;
use Modules\Achievements\Application\DTOs\SourceRecalculationDTO;
use Modules\Achievements\Application\Enums\RecalculationOutcome;
use Modules\Achievements\Application\Services\ActivityLedgerInterface;

/**
 * Rebuilds the ledger from the history of the activity sources: once after installing the module,
 * and whenever a source was missing or failed for a while. It can be repeated; it sends no notices.
 */
final class RecalculateAchievementFactsCommand extends Command
{
    /** @var string */
    protected $signature = 'achievements:recalculate
        {--source= : Only this source type (e.g. game-tables.session)}
        {--dry-run : Count what would change without writing anything}';

    /** @var string */
    protected $description = 'Rebuild the achievements ledger from the history of the activity sources';

    public function handle(ActivityLedgerInterface $ledger): int
    {
        $source = $this->option('source');
        $source = is_string($source) && trim($source) !== '' ? trim($source) : null;
        $dryRun = (bool) $this->option('dry-run');

        $results = $ledger->recalculate($source, $dryRun);

        if ($results === []) {
            $this->components->info((string) __('achievements::messages.recalculate.no_sources'));
        }

        foreach ($results as $result) {
            $this->report($result);
        }

        if ($dryRun) {
            $this->components->info((string) __('achievements::messages.recalculate.dry_run'));
        }

        foreach ($results as $result) {
            if (! $result->isComplete()) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function report(SourceRecalculationDTO $result): void
    {
        $counts = [
            'type' => $result->sourceType,
            'subjects' => $result->subjects,
            'changed' => $result->changed,
            'removed' => $result->removed,
        ];

        match ($result->outcome) {
            RecalculationOutcome::Complete => $this->components->info((string) __('achievements::messages.recalculate.complete', $counts)),
            RecalculationOutcome::Missing => $this->components->warn((string) __('achievements::messages.recalculate.missing', $counts)),
            RecalculationOutcome::Failed => $this->components->error((string) __('achievements::messages.recalculate.failed', $counts)),
            RecalculationOutcome::Cycle => $this->components->error((string) __('achievements::messages.recalculate.cycle', $counts)),
        };
    }
}
