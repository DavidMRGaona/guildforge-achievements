<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Community;

use App\Application\Activity\DTOs\ActivitySourceSummaryDTO;
use App\Application\Activity\Services\ActivityReaderInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Modules\Achievements\Application\Services\CommunityMetricsInterface;
use Modules\Achievements\Application\Services\MentorDecisionsInterface;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Repositories\MentorDecisionRepositoryInterface;
use Modules\Achievements\Domain\Repositories\RecalculationMarkRepositoryInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\MentorRule;
use Modules\Achievements\Domain\ValueObjects\MentorDecision;
use Modules\Achievements\Infrastructure\Support\AchievementWarnings;

/**
 * Walks the players without a decision in chunks, reads their first game from the ledger and
 * writes a decision for those past the grace (with nobody when the game has no mentor). The rest
 * wait for a later night. The progress cached for the mentors decided is dropped, so their
 * profile shows the new count at once.
 *
 * Nothing is decided until every source of game sessions offered now was recalculated whole once
 * (`achievements:recalculate`): before that, the ledger may lack the games played before the module
 * was installed, and a decision is for good. Warned once a day while it waits.
 */
final readonly class LedgerMentorDecisions implements MentorDecisionsInterface
{
    public function __construct(
        private AchievementFactRepositoryInterface $facts,
        private MentorDecisionRepositoryInterface $decisions,
        private CommunityMetricsInterface $community,
        private ActivityReaderInterface $reader,
        private RecalculationMarkRepositoryInterface $marks,
        private AchievementWarnings $warnings,
    ) {}

    public function decide(): int
    {
        if (! $this->ledgerIsWhole()) {
            return 0;
        }

        $now = DateTimeImmutable::createFromInterface(Date::now());
        $decided = 0;
        $after = null;

        while (($chunk = $this->decisions->undecidedNewcomers(AchievementRule::MENTOR_KIND, AchievementRule::NEWCOMER_ROLE, $after, self::NEWCOMERS_PER_CHUNK)) !== []) {
            $first = $this->facts->firstFacts($chunk, AchievementRule::MENTOR_KIND, AchievementRule::NEWCOMER_ROLE, AchievementRule::MENTOR_ROLE, AchievementRule::CREATOR_ROLE);
            $decisions = [];

            foreach ($first as $fact) {
                if (MentorRule::isDecided($fact->occurredAt, $now)) {
                    $decisions[] = new MentorDecision($fact->userId, MentorRule::mentor($fact->userId, $fact->subjects), MentorRule::decidedAt($fact->occurredAt));
                }
            }

            $decided += $this->decisions->record($decisions);
            // Their cached progress counted the newcomers decided before tonight
            $this->community->forgetProgress(array_values(array_filter(array_map(static fn (MentorDecision $decision): ?string => $decision->mentorId, $decisions))));
            $after = array_last($chunk);
        }

        return $decided;
    }

    private function ledgerIsWhole(): bool
    {
        $types = array_values(array_map(
            static fn (ActivitySourceSummaryDTO $source): string => $source->type,
            array_filter($this->reader->sources(), static fn (ActivitySourceSummaryDTO $source): bool => $source->kind->value === AchievementRule::MENTOR_KIND),
        ));

        // No source of game sessions now (its module disabled): nothing new to decide on
        if ($types === []) {
            return false;
        }

        $missing = array_values(array_diff($types, $this->marks->completedAmong($types)));

        if ($missing !== []) {
            $this->warnings->warn('mentors_waiting_for_recalculation', '[Achievements] Mentors are not decided until the game sessions are recalculated once (achievements:recalculate).', [
                'reason' => 'mentors_waiting_for_recalculation',
                'source_types' => $missing,
            ]);

            return false;
        }

        return true;
    }
}
