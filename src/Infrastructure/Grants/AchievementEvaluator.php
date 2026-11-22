<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Grants;

use App\Application\Services\TimezoneServiceInterface;
use App\Domain\Activity\ActivityIdentifierRule;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\DTOs\EvaluationResultDTO;
use Modules\Achievements\Application\DTOs\GrantedAchievementDTO;
use Modules\Achievements\Application\Enums\AchievementStatus;
use Modules\Achievements\Application\Enums\EvaluationOutcome;
use Modules\Achievements\Application\Services\AchievementEvaluatorInterface;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\AchievementStatusRule;
use Modules\Achievements\Application\Services\ActivityMetricsInterface;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\MetricType;
use Modules\Achievements\Domain\Rules\RuleEvaluator;
use Modules\Achievements\Domain\Rules\YearsBasis;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Infrastructure\Support\AchievementWarnings;

final readonly class AchievementEvaluator implements AchievementEvaluatorInterface
{
    public function __construct(
        private AchievementsSettingsInterface $settings,
        private ActivityMetricsInterface $metrics,
        private AchievementRepositoryInterface $achievements,
        private AchievementFactRepositoryInterface $facts,
        private AchievementGrantRepositoryInterface $grants,
        private AchievementGranterInterface $granter,
        private AchievementStatusRule $statusRule,
        private RuleEvaluator $rules,
        private TimezoneServiceInterface $time,
        private AchievementWarnings $warnings,
    ) {}

    public function evaluate(array $userIds, bool $silent, ?array $achievementIds = null, bool $dryRun = false): EvaluationResultDTO
    {
        $evaluable = $this->evaluable($achievementIds, onlyAccountYears: false);

        if ($evaluable === null) {
            return EvaluationResultDTO::settingsUnreadable();
        }

        return $this->evaluateUsers($this->userIds($userIds), $evaluable, $silent, $dryRun);
    }

    public function evaluateEverybody(?array $achievementIds, bool $silent, bool $dryRun = false, bool $onlyAccountYears = false): EvaluationResultDTO
    {
        $evaluable = $this->evaluable($achievementIds, $onlyAccountYears);

        if ($evaluable === null) {
            return EvaluationResultDTO::settingsUnreadable();
        }

        $kinds = $this->kinds($evaluable);
        $granted = [];
        $users = 0;
        $after = null;

        while ($kinds !== []) {
            $chunk = $this->facts->userIdsWithKinds($kinds, $after, self::USERS_PER_CHUNK);

            if ($chunk === []) {
                break;
            }

            $result = $this->evaluateUsers($chunk, $evaluable, $silent, $dryRun);
            array_push($granted, ...$result->granted);
            $users += $result->users;
            $after = array_last($chunk);
        }

        return new EvaluationResultDTO(EvaluationOutcome::Evaluated, $granted, $users, count($evaluable));
    }

    /**
     * @param  list<string>  $userIds
     * @param  list<array{Achievement, AchievementRule}>  $evaluable
     */
    private function evaluateUsers(array $userIds, array $evaluable, bool $silent, bool $dryRun): EvaluationResultDTO
    {
        if ($userIds === [] || $evaluable === []) {
            return new EvaluationResultDTO(EvaluationOutcome::Evaluated, [], count($userIds), count($evaluable));
        }

        $kinds = $this->kinds($evaluable);
        $now = DateTimeImmutable::createFromInterface(Date::now());
        $zone = $this->time->displayTimezone();
        $granted = [];

        foreach (array_chunk($userIds, self::USERS_PER_CHUNK) as $chunk) {
            $facts = $this->facts->forUsers($chunk, $kinds);
            $held = $this->grants->heldAchievementIds($chunk);

            foreach ($chunk as $userId) {
                $personFacts = $facts[$userId] ?? [];

                // Every rule needs at least one fact of its kind
                if ($personFacts === []) {
                    continue;
                }

                $holds = array_fill_keys($held[$userId] ?? [], true);

                foreach ($evaluable as [$achievement, $rule]) {
                    if (isset($holds[$achievement->id]) || ! $this->rules->isMet($rule, $personFacts, $now, $zone)) {
                        continue;
                    }

                    if ($dryRun) {
                        $granted[] = new GrantedAchievementDTO($userId, $achievement->id);

                        continue;
                    }

                    $grant = $this->granter->grant(new NewGrant($userId, $achievement->id, GrantSource::Rule, $silent));

                    if ($grant instanceof AchievementGrant) {
                        $granted[] = new GrantedAchievementDTO($userId, $achievement->id);
                    }
                }
            }
        }

        return new EvaluationResultDTO(EvaluationOutcome::Evaluated, $granted, count($userIds), count($evaluable));
    }

    /**
     * The achievements to evaluate with their rules, or null when the settings cannot be read.
     *
     * @param  list<string>|null  $achievementIds
     * @return list<array{Achievement, AchievementRule}>|null
     */
    private function evaluable(?array $achievementIds, bool $onlyAccountYears): ?array
    {
        $settings = $this->settings->readStrictly();

        if (! $settings instanceof AchievementsSettings) {
            $this->warnings->warn('settings_unreadable', '[Achievements] The module settings could not be read: no achievement was evaluated.', [
                'reason' => 'settings_unreadable',
            ]);

            return null;
        }

        $wanted = $achievementIds === null ? null : array_fill_keys(array_map(strtolower(...), $achievementIds), true);
        $kinds = $this->metrics->kindsWithSource();
        $evaluable = [];

        foreach ($this->achievements->active() as $achievement) {
            if ($wanted !== null && ! isset($wanted[strtolower($achievement->id)])) {
                continue;
            }

            $status = $this->statusRule->status($achievement, $settings, $kinds);

            if ($status === AchievementStatus::InvalidRule) {
                $this->warnings->warn('rule_unreadable:'.$achievement->id, '[Achievements] The rule of an achievement cannot be read: it is not evaluated.', [
                    'achievement_id' => $achievement->id,
                    'reason' => 'rule_unreadable',
                ]);
            }

            $rule = $achievement->rule;

            if (! $status->isEvaluated() || ! $rule instanceof AchievementRule) {
                continue;
            }

            if ($onlyAccountYears && ($rule->metric !== MetricType::Years || $rule->basis !== YearsBasis::Account)) {
                continue;
            }

            $evaluable[] = [$achievement, $rule];
        }

        return $evaluable;
    }

    /**
     * @param  list<array{Achievement, AchievementRule}>  $evaluable
     * @return list<string>
     */
    private function kinds(array $evaluable): array
    {
        $kinds = [];

        foreach ($evaluable as [, $rule]) {
            $kind = $rule->evaluatedKind();

            if ($kind !== null) {
                $kinds[$kind] = true;
            }
        }

        return array_keys($kinds);
    }

    /**
     * @param  list<string>  $userIds
     * @return list<string>
     */
    private function userIds(array $userIds): array
    {
        $valid = [];

        foreach ($userIds as $userId) {
            $id = ActivityIdentifierRule::userId($userId);

            if ($id !== null) {
                $valid[$id] = true;
            }
        }

        $valid = array_keys($valid);
        sort($valid, SORT_STRING);

        return $valid;
    }
}
