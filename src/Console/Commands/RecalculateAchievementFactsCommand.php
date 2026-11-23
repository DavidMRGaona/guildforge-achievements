<?php

declare(strict_types=1);

namespace Modules\Achievements\Console\Commands;

use App\Domain\Activity\ActivityIdentifierRule;
use Illuminate\Console\Command;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\DTOs\EvaluationResultDTO;
use Modules\Achievements\Application\DTOs\SourceRecalculationDTO;
use Modules\Achievements\Application\Enums\RecalculationOutcome;
use Modules\Achievements\Application\Services\AchievementEvaluatorInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\AchievementStatusRule;
use Modules\Achievements\Application\Services\ActivityLedgerInterface;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Domain\Repositories\RecalculationMarkRepositoryInterface;

/**
 * Rebuilds the ledger from the history of the activity sources: once after installing the module,
 * and whenever a source was missing or failed for a while. The accounts it touches are evaluated
 * again on the queue, silently. It can be repeated; it sends no notices.
 *
 * With --user or --achievement it reads no source: it evaluates from the ledger as it is, now and
 * silently, one account, one achievement for everybody, or one achievement for one account.
 */
final class RecalculateAchievementFactsCommand extends Command
{
    /** @var string */
    protected $signature = 'achievements:recalculate
        {--source= : Only this source type (e.g. game-tables.session)}
        {--user= : Only evaluate this account, from the ledger (no source is read)}
        {--achievement= : Only evaluate this achievement (its id), for everybody, from the ledger}
        {--dry-run : Count what would change without writing anything}';

    /** @var string */
    protected $description = 'Rebuild the achievements ledger from the history of the activity sources';

    public function handle(ActivityLedgerInterface $ledger): int
    {
        $source = $this->textOption('source');
        $dryRun = (bool) $this->option('dry-run');
        $user = $this->textOption('user');
        $achievement = $this->textOption('achievement');

        if ($user !== null || $achievement !== null) {
            if ($source !== null) {
                $this->components->error((string) __('achievements::messages.recalculate.source_alone'));

                return self::INVALID;
            }

            return $this->evaluateFromLedger($user, $achievement, $dryRun);
        }

        $results = $ledger->recalculate($source, $dryRun);

        // A source read whole: mentors of its kind can be decided for good from now on (#37)
        if (! $dryRun) {
            $marks = $this->laravel->make(RecalculationMarkRepositoryInterface::class);

            foreach ($results as $result) {
                if ($result->isComplete()) {
                    $marks->markComplete($result->sourceType);
                }
            }
        }

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

    private function evaluateFromLedger(?string $user, ?string $achievementId, bool $dryRun): int
    {
        $userId = $user === null ? null : ActivityIdentifierRule::userId($user);

        if ($user !== null && $userId === null) {
            $this->components->error((string) __('achievements::messages.recalculate.invalid_user', ['user' => $user]));

            return self::INVALID;
        }

        $achievement = $achievementId === null ? null : $this->laravel->make(AchievementRepositoryInterface::class)->find($achievementId);

        if ($achievementId !== null && ! $achievement instanceof Achievement) {
            $this->components->error((string) __('achievements::messages.recalculate.unknown_achievement', ['achievement' => $achievementId]));

            return self::INVALID;
        }

        $settings = $this->laravel->make(AchievementsSettingsInterface::class)->readStrictly();

        if (! $settings instanceof AchievementsSettings) {
            $this->components->error((string) __('achievements::messages.recalculate.settings_unreadable'));

            return self::FAILURE;
        }

        if ($achievement instanceof Achievement) {
            $status = $this->laravel->make(AchievementStatusRule::class)
                ->status($achievement, $settings, $this->laravel->make(ActivityMetricsInterface::class)->kindsWithSource());

            if (! $status->isEvaluated()) {
                $this->components->warn((string) __('achievements::messages.recalculate.not_evaluated', ['status' => $status->label()]));

                return self::SUCCESS;
            }
        }

        $evaluator = $this->laravel->make(AchievementEvaluatorInterface::class);
        $ids = $achievement instanceof Achievement ? [$achievement->id] : null;
        $result = $userId !== null
            ? $evaluator->evaluate([$userId], silent: true, achievementIds: $ids, dryRun: $dryRun)
            : $evaluator->evaluateEverybody($ids, silent: true, dryRun: $dryRun);

        return $this->reportEvaluation($result, $dryRun);
    }

    private function reportEvaluation(EvaluationResultDTO $result, bool $dryRun): int
    {
        if (! $result->isEvaluated()) {
            $this->components->error((string) __('achievements::messages.recalculate.settings_unreadable'));

            return self::FAILURE;
        }

        $this->components->info((string) __($dryRun ? 'achievements::messages.recalculate.evaluated_dry_run' : 'achievements::messages.recalculate.evaluated', [
            'users' => $result->users,
            'achievements' => $result->achievements,
            'granted' => count($result->granted),
        ]));

        return self::SUCCESS;
    }

    private function textOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
