<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Challenges;

use App\Application\Authorization\Services\AssociationMembersAmongInterface;
use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\DTOs\ChallengeEvaluationResultDTO;
use Modules\Achievements\Application\Enums\ChallengeEvaluationOutcome;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Application\Services\ChallengeEvaluatorInterface;
use Modules\Achievements\Domain\Challenges\ChallengeAudience;
use Modules\Achievements\Domain\Challenges\ChallengeCalculator;
use Modules\Achievements\Domain\Challenges\ChallengeProgress;
use Modules\Achievements\Domain\Challenges\ChallengeRule;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Entities\AchievementFact;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Entities\Challenge;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Domain\Repositories\ChallengeRepositoryInterface;
use Modules\Achievements\Domain\Rules\AnnouncementWindow;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Infrastructure\Support\AchievementWarnings;
use Throwable;

/**
 * The dates are calendar days of the display zone: a challenge «del 1 al 31 de diciembre» counts
 * the facts from the first minute of the 1st to the last of the 31st there, whatever the storage
 * zone. Who is an association member is asked to the core (AssociationMembersAmongInterface), never
 * worked out from role names here.
 */
final readonly class ChallengeEvaluator implements ChallengeEvaluatorInterface
{
    private const int CHUNK = 500;

    public function __construct(
        private AchievementsSettingsInterface $settings,
        private ActivityMetricsInterface $metrics,
        private ChallengeRepositoryInterface $challenges,
        private AchievementFactRepositoryInterface $facts,
        private AchievementRepositoryInterface $achievements,
        private AchievementGrantRepositoryInterface $grants,
        private AchievementGranterInterface $granter,
        private AssociationMembersAmongInterface $members,
        private ChallengeCalculator $calculator,
        private TimezoneServiceInterface $time,
        private AchievementWarnings $warnings,
    ) {}

    public function evaluate(?array $challengeIds, bool $silent): ChallengeEvaluationResultDTO
    {
        $settings = $this->settings->readStrictly();

        if (! $settings instanceof AchievementsSettings) {
            $this->warnings->warn('settings_unreadable', '[Achievements] The module settings could not be read: no challenge was counted.', [
                'reason' => 'settings_unreadable',
            ]);

            return new ChallengeEvaluationResultDTO(ChallengeEvaluationOutcome::SettingsUnreadable);
        }

        if (! $settings->challenges) {
            return new ChallengeEvaluationResultDTO(ChallengeEvaluationOutcome::Off);
        }

        $today = $this->time->today()->format('Y-m-d');
        $kinds = $this->metrics->kindsWithSource();
        $now = DateTimeImmutable::createFromInterface(Date::now());
        $counted = 0;
        $completed = [];
        $granted = 0;
        $retry = [];

        foreach ($this->challenges->counted($today, $challengeIds) as $challenge) {
            $rule = $challenge->rule;

            if (! $rule instanceof ChallengeRule) {
                $this->warnings->warn('challenge_rule_unreadable:'.$challenge->id, '[Achievements] The rule of a challenge cannot be read: it is not counted.', [
                    'challenge_id' => $challenge->id,
                    'reason' => 'challenge_rule_unreadable',
                ]);

                continue;
            }

            // Without a source or switched off, what is stored stays: an empty count would be a lie
            if (! isset($kinds[$rule->kind]) || ! $settings->metricKindEnabled($rule->kind)) {
                continue;
            }

            $facts = $this->factsOf($challenge, $rule);
            $members = $this->membersAmong($challenge, $facts);

            if ($members === false) {
                $retry[] = $challenge->id;

                continue;
            }

            $progress = $this->calculator->progress($rule, $facts, $members);
            $saved = $this->challenges->saveProgress($challenge->id, $progress, $now);

            if (! $saved instanceof Challenge) {
                continue;
            }

            $counted++;

            if ($saved->completedAt instanceof DateTimeImmutable) {
                $completed[] = $saved->id;
                $granted += $this->reward($saved, $saved->completedAt, $progress, $silent, $now);
            }
        }

        return new ChallengeEvaluationResultDTO(ChallengeEvaluationOutcome::Evaluated, $counted, $completed, $granted, $retry);
    }

    /**
     * @return list<AchievementFact>
     */
    private function factsOf(Challenge $challenge, ChallengeRule $rule): array
    {
        [$from] = $this->time->displayDayRange($challenge->startsOn);
        [, $until] = $this->time->displayDayRange($challenge->endsOn);

        return $this->facts->forKindBetween($rule->kind, $from, $until);
    }

    /**
     * The association members among the people of the facts, null when everybody counts, false when
     * they cannot be read now (the challenge is left as it was: never counted as if nobody were one).
     *
     * @param  list<AchievementFact>  $facts
     * @return array<string, true>|false|null
     */
    private function membersAmong(Challenge $challenge, array $facts): array|false|null
    {
        if ($challenge->audience !== ChallengeAudience::Members) {
            return null;
        }

        $userIds = array_values(array_unique(array_map(static fn (AchievementFact $fact): string => strtolower($fact->userId), $facts)));
        $members = [];

        try {
            foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
                $members += $this->members->among($chunk);
            }
        } catch (Throwable $exception) {
            $this->warnings->warn('challenge_members_unavailable', '[Achievements] The association members could not be read: a challenge for members was not counted.', [
                'challenge_id' => $challenge->id,
                'exception' => $exception::class,
                'reason' => 'members_unavailable',
            ]);

            return false;
        }

        return $members;
    }

    /**
     * Grants the reward to whoever added to a complete challenge and does not hold it yet (nor had it
     * taken away by hand). Announced only for a recent completion or contribution.
     */
    private function reward(Challenge $challenge, DateTimeImmutable $completedAt, ChallengeProgress $progress, bool $silent, DateTimeImmutable $now): int
    {
        $reward = $this->achievements->find($challenge->rewardAchievementId);

        if (! $reward instanceof Achievement || ! $reward->active) {
            $this->warnings->warn('challenge_reward_unavailable:'.$challenge->id, '[Achievements] The reward of a complete challenge is gone or switched off: nobody gets it until it is back.', [
                'challenge_id' => $challenge->id,
                'reason' => 'reward_unavailable',
            ]);

            return 0;
        }

        $recentSince = $now->modify('-'.AnnouncementWindow::ACTIVITY_DAYS.' days');
        $granted = 0;

        foreach (array_chunk($progress->contributors(), self::CHUNK) as $chunk) {
            $held = $this->grants->heldAchievementIds($chunk);
            $revoked = $this->grants->revokedAchievementIds($chunk);

            foreach ($chunk as $userId) {
                if (in_array($reward->id, $held[$userId] ?? [], true) || in_array($reward->id, $revoked[$userId] ?? [], true)) {
                    continue;
                }

                // The moment that made it theirs: the completion, or their own later contribution
                $moment = max($completedAt, $progress->lastContributionOf($userId) ?? $completedAt);
                $quiet = $silent || $moment < $recentSince;

                if ($this->granter->grant(new NewGrant($userId, $reward->id, GrantSource::Challenge, $quiet)) instanceof AchievementGrant) {
                    $granted++;
                }
            }
        }

        return $granted;
    }
}
