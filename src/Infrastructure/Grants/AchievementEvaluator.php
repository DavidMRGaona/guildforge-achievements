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
use Modules\Achievements\Application\Services\CommunityMetricsInterface;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\AnnouncementWindow;
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
        private AnnouncementWindow $window,
        private TimezoneServiceInterface $time,
        private AchievementWarnings $warnings,
        private CommunityMetricsInterface $community,
    ) {}

    public function evaluate(array $userIds, bool $silent, ?array $achievementIds = null, bool $dryRun = false): EvaluationResultDTO
    {
        $selection = $this->evaluable($achievementIds, onlyAccountYears: false, onlyKinds: null, onlyNightly: false);

        if ($selection === null) {
            return EvaluationResultDTO::settingsUnreadable();
        }

        [$evaluable, $pending] = $selection;
        $result = $this->evaluateUsers($this->userIds($userIds), $evaluable, $silent, $dryRun);

        return new EvaluationResultDTO(EvaluationOutcome::Evaluated, $result->granted, $result->users, $result->achievements, $pending);
    }

    public function evaluateEverybody(?array $achievementIds, bool $silent, bool $dryRun = false, bool $onlyAccountYears = false, ?array $onlyKinds = null, bool $onlyNightly = false): EvaluationResultDTO
    {
        $selection = $this->evaluable($achievementIds, $onlyAccountYears, $onlyKinds, $onlyNightly);

        if ($selection === null) {
            return EvaluationResultDTO::settingsUnreadable();
        }

        [$evaluable, $pending] = $selection;

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

        return new EvaluationResultDTO(EvaluationOutcome::Evaluated, $granted, $users, count($evaluable), $pending);
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
        // Community rules count other people, never the person's own facts: those are read only for
        // the rules that measure them (the nightly batch reads none)
        $needsFacts = array_any($evaluable, static fn (array $entry): bool => ! $entry[1]->isCommunity());
        $now = DateTimeImmutable::createFromInterface(Date::now());
        $zone = $this->time->displayTimezone();
        $granted = [];

        foreach (array_chunk($userIds, self::USERS_PER_CHUNK) as $chunk) {
            $facts = $needsFacts ? $this->facts->forUsers($chunk, $kinds) : [];
            $held = $this->grants->heldSeasonKeys($chunk);
            // Taken away by hand: never granted again by a rule, nor counted by a dry run
            $revoked = $this->grants->revokedSeasonKeys($chunk);
            $people = $this->communityPeople($evaluable, $chunk);

            foreach ($chunk as $userId) {
                $personFacts = $facts[$userId] ?? [];

                // Every rule that measures the person's facts needs at least one of its kind
                if ($needsFacts && $personFacts === []) {
                    continue;
                }

                $taken = $held[$userId] ?? [];

                foreach ($revoked[$userId] ?? [] as $achievementId => $seasons) {
                    $taken[$achievementId] = ($taken[$achievementId] ?? []) + $seasons;
                }

                foreach ($evaluable as [$achievement, $rule]) {
                    // A seasonal rule is asked about every season it has facts in (a recalculation
                    // grants past seasons too); any other rule once, without a season
                    $seasons = $rule->isSeasonal() ? $this->rules->seasons($rule, $personFacts, $now, $zone) : [null];

                    foreach ($seasons as $season) {
                        $seasonKey = $season?->key() ?? '';

                        if (isset($taken[strtolower($achievement->id)][$seasonKey])) {
                            continue;
                        }

                        $metAt = $this->rules->metAt($rule, $personFacts, $now, $zone, $people[$achievement->id][$userId] ?? null, $season);

                        if (! $metAt instanceof DateTimeImmutable) {
                            continue;
                        }

                        // Met long ago: granted without a notice, whatever asked for this evaluation
                        $quiet = $silent || ! $this->window->isRecent($rule, $metAt, $now, $zone);

                        if ($dryRun) {
                            $granted[] = new GrantedAchievementDTO($userId, $achievement->id, $quiet, $season?->key());

                            continue;
                        }

                        $grant = $this->granter->grant(new NewGrant($userId, $achievement->id, GrantSource::Rule, $quiet, season: $season?->key()));

                        if ($grant instanceof AchievementGrant) {
                            $granted[] = new GrantedAchievementDTO($userId, $achievement->id, $quiet, $season?->key());
                        }
                    }
                }
            }
        }

        return new EvaluationResultDTO(EvaluationOutcome::Evaluated, $granted, count($userIds), count($evaluable));
    }

    /**
     * The people each community rule counts for each account of the chunk, keyed by achievement: one
     * aggregate query per rule shape, shared by the rules of the same shape. A ledger that cannot be
     * read throws, so nothing is granted on a guess.
     *
     * @param  list<array{Achievement, AchievementRule}>  $evaluable
     * @param  list<string>  $userIds
     * @return array<string, array<string, list<DateTimeImmutable>>>
     */
    private function communityPeople(array $evaluable, array $userIds): array
    {
        $byShape = [];
        $people = [];

        foreach ($evaluable as [$achievement, $rule]) {
            if (! $rule->isCommunity()) {
                continue;
            }

            $shape = $rule->peopleShape();
            $people[$achievement->id] = $byShape[$shape] ??= $this->community->people($rule, $userIds);
        }

        return $people;
    }

    /**
     * The achievements to evaluate with their rules, and the kinds left out while their source is
     * unavailable; null when the settings cannot be read. The nightly batch takes the rules left to
     * it (MetricType::isNightly()) and only those; every other evaluation leaves them out.
     *
     * @param  list<string>|null  $achievementIds
     * @param  list<string>|null  $onlyKinds
     * @return array{list<array{Achievement, AchievementRule}>, list<string>}|null
     */
    private function evaluable(?array $achievementIds, bool $onlyAccountYears, ?array $onlyKinds, bool $onlyNightly): ?array
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
        $kindFilter = $onlyKinds === null ? null : array_fill_keys($onlyKinds, true);
        $evaluable = [];
        $pending = [];

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
            $kind = $rule?->evaluatedKind();

            if ($kindFilter !== null && ($kind === null || ! isset($kindFilter[$kind]))) {
                continue;
            }

            if ($status === AchievementStatus::NoSource && $kind !== null && $this->metrics->isPending($kind)) {
                $pending[$kind] = $kind;
            }

            if (! $status->isEvaluated() || ! $rule instanceof AchievementRule) {
                continue;
            }

            if ($rule->isNightly() !== $onlyNightly) {
                continue;
            }

            if ($onlyAccountYears && ($rule->metric !== MetricType::Years || $rule->basis !== YearsBasis::Account)) {
                continue;
            }

            $evaluable[] = [$achievement, $rule];
        }

        return [$evaluable, array_values($pending)];
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
