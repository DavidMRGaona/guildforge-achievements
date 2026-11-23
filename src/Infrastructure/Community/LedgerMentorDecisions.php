<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Community;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Modules\Achievements\Application\Services\CommunityMetricsInterface;
use Modules\Achievements\Application\Services\MentorDecisionsInterface;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Repositories\MentorDecisionRepositoryInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\MentorRule;
use Modules\Achievements\Domain\ValueObjects\MentorDecision;

/**
 * Walks the players without a decision in chunks, reads their first game from the ledger and
 * writes a decision for those past the grace (with nobody when the game has no mentor). The rest
 * wait for a later night. The progress cached for the mentors decided is dropped, so their
 * profile shows the new count at once.
 */
final readonly class LedgerMentorDecisions implements MentorDecisionsInterface
{
    public function __construct(
        private AchievementFactRepositoryInterface $facts,
        private MentorDecisionRepositoryInterface $decisions,
        private CommunityMetricsInterface $community,
    ) {}

    public function decide(): int
    {
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
}
