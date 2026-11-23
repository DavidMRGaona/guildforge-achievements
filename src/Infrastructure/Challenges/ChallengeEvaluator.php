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
use Modules\Achievements\Domain\Challenges\ChallengeRewardRule;
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
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Infrastructure\Support\AchievementWarnings;
use Throwable;

/**
 * The dates are calendar days of the display zone: a challenge «del 1 al 31 de diciembre» counts
 * the facts from the first minute of the 1st to the last of the 31st there, whatever the storage
 * zone. Who is an association member is asked to the core (AssociationMembersAmongInterface), never
 * worked out from role names here, and is frozen once the days are over: the late facts' days are
 * for facts recorded late, not for people who join or leave meanwhile.
 *
 * Rewards are granted only by counts that are not silent, and always announced: the moment that
 * counts is when the completion (or a person's contribution) is found. A silent count (a
 * recalculation) still stores the total and the completion, and the next count with notices grants.
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

    public function evaluate(?array $challengeIds, bool $silent, bool $publish = false): ChallengeEvaluationResultDTO
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
            $members = $this->membersAmong($challenge, $facts, $today, $now);

            if ($members === false) {
                $retry[] = $challenge->id;

                continue;
            }

            $progress = $this->calculator->progress($rule, $facts, $members);
            // Discarded when the challenge was saved from the panel meanwhile: it is counted again
            $saved = $this->challenges->saveProgress($challenge->id, $progress, $now, $challenge->countVersion);

            if (! $saved instanceof Challenge) {
                continue;
            }

            $counted++;

            if ($saved->completedAt instanceof DateTimeImmutable) {
                $completed[] = $saved->id;

                if (! $silent) {
                    $granted += $this->reward($saved, $progress);
                }
            }
        }

        // Also what the counts of the day stored (a challenge left for a retry keeps its last one)
        if ($publish) {
            $this->challenges->publishShown($now);
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
     * Within the days, everybody is asked again and remembered. Once they are over, what was
     * remembered stays as it was; only people never asked before (a late fact) are asked, once.
     *
     * @param  list<AchievementFact>  $facts
     * @return array<string, true>|false|null
     */
    private function membersAmong(Challenge $challenge, array $facts, string $today, DateTimeImmutable $now): array|false|null
    {
        if ($challenge->audience !== ChallengeAudience::Members) {
            return null;
        }

        $userIds = array_values(array_unique(array_map(static fn (AchievementFact $fact): string => strtolower($fact->userId), $facts)));
        $ended = $challenge->hasEnded($today);
        $known = $ended ? $this->challenges->audienceOf($challenge->id) : [];
        $ask = $ended ? array_values(array_filter($userIds, static fn (string $id): bool => ! array_key_exists($id, $known))) : $userIds;
        $answers = [];

        try {
            foreach (array_chunk($ask, self::CHUNK) as $chunk) {
                $members = $this->members->among($chunk);

                foreach ($chunk as $userId) {
                    $answers[$userId] = isset($members[$userId]);
                }
            }
        } catch (Throwable $exception) {
            $this->warnings->warn('challenge_members_unavailable', '[Achievements] The association members could not be read: a challenge for members was not counted.', [
                'challenge_id' => $challenge->id,
                'exception' => $exception::class,
                'reason' => 'members_unavailable',
            ]);

            return false;
        }

        if ($answers !== [] || ! $ended) {
            $this->challenges->rememberAudience($challenge->id, $answers, replace: ! $ended, at: $now);
        }

        $members = [];

        foreach ($known + $answers as $userId => $isMember) {
            if ($isMember) {
                $members[(string) $userId] = true;
            }
        }

        return $members;
    }

    /**
     * Grants the reward to whoever added to a complete challenge and does not hold it yet (nor had it
     * taken away by hand), while the reward is still one (ChallengeRewardRule). Each new grant is
     * announced: it was just found.
     */
    private function reward(Challenge $challenge, ChallengeProgress $progress): int
    {
        $reward = $this->achievements->find($challenge->rewardAchievementId);

        if (! $reward instanceof Achievement || ! ChallengeRewardRule::isUsable($reward)) {
            $this->warnings->warn('challenge_reward_unavailable:'.$challenge->id, '[Achievements] The reward of a complete challenge is gone, switched off, secret or granted by a rule: nobody gets it until it is a reward again.', [
                'challenge_id' => $challenge->id,
                'reason' => 'reward_unavailable',
            ]);

            return 0;
        }

        $granted = 0;
        // A reward of a challenge for members says its holder is one: told apart in the summary
        $source = $challenge->audience === ChallengeAudience::Members ? GrantSource::MemberChallenge : GrantSource::Challenge;

        foreach (array_chunk($progress->contributors(), self::CHUNK) as $chunk) {
            // Under the challenge's row lock, while it is open: once closed, nobody else gets it
            $open = $this->challenges->whileOpen($challenge->id, function () use ($chunk, $reward, $source, &$granted): void {
                $held = $this->grants->heldAchievementIds($chunk);
                // A challenge's reward has no season: taken away by hand in that one ('')
                $revoked = array_fill_keys($this->grants->revokedAmong($reward->id, $chunk), true);

                foreach ($chunk as $userId) {
                    if (in_array($reward->id, $held[$userId] ?? [], true) || isset($revoked[$userId])) {
                        continue;
                    }

                    if ($this->granter->grant(new NewGrant($userId, $reward->id, $source, false)) instanceof AchievementGrant) {
                        $granted++;
                    }
                }
            });

            if (! $open) {
                break;
            }
        }

        return $granted;
    }
}
