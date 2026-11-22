<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Rules;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Achievements\Domain\Entities\AchievementFact;

/**
 * How far one person is in a rule, from their facts in the ledger. Pure: the facts, the instant and
 * the display zone come from outside, so the same value serves to grant and to show progress.
 *
 * - count: subjects (source type + subject id) of the rule's kind with a fact that passes every
 *   filter; a person with several roles in one subject counts it once.
 * - distinct: different value ids of the attribute among those facts (facts without it left out).
 * - years, account: full years from the earliest account fact to now, as calendar dates in the
 *   display zone; membership: membership year subjects.
 * - manual: always 0, never met.
 */
final class RuleEvaluator
{
    /**
     * @param  list<AchievementFact>  $facts  the person's facts (other kinds are ignored)
     */
    public function isMet(AchievementRule $rule, array $facts, DateTimeImmutable $now, DateTimeZone $displayZone): bool
    {
        return ! $rule->isManual() && $this->value($rule, $facts, $now, $displayZone) >= $rule->threshold;
    }

    /**
     * @param  list<AchievementFact>  $facts
     */
    public function value(AchievementRule $rule, array $facts, DateTimeImmutable $now, DateTimeZone $displayZone): int
    {
        return match ($rule->metric) {
            MetricType::Count => count($this->subjects($rule, $facts)),
            MetricType::Distinct => $this->distinctValues($rule, $facts),
            MetricType::Years => $rule->basis === YearsBasis::Account
                ? $this->accountYears($facts, $now, $displayZone)
                : count($this->subjects($rule, $facts)),
            MetricType::Manual => 0,
        };
    }

    /**
     * @param  list<AchievementFact>  $facts
     * @return array<string, true>
     */
    private function subjects(AchievementRule $rule, array $facts): array
    {
        $subjects = [];

        foreach ($this->matching($rule, $facts) as $fact) {
            // Source types never hold a NUL, so the key never mixes two subjects
            $subjects[$fact->sourceType."\0".$fact->subjectId] = true;
        }

        return $subjects;
    }

    /**
     * @param  list<AchievementFact>  $facts
     */
    private function distinctValues(AchievementRule $rule, array $facts): int
    {
        $values = [];

        foreach ($this->matching($rule, $facts) as $fact) {
            $value = $rule->distinctKey === null ? null : ($fact->attributes[$rule->distinctKey] ?? null);

            if ($value !== null) {
                $values[$value->id] = true;
            }
        }

        return count($values);
    }

    /**
     * @param  list<AchievementFact>  $facts
     */
    private function accountYears(array $facts, DateTimeImmutable $now, DateTimeZone $displayZone): int
    {
        $first = null;

        foreach ($facts as $fact) {
            if ($fact->kind === YearsBasis::Account->kind() && ($first === null || $fact->occurredAt < $first)) {
                $first = $fact->occurredAt;
            }
        }

        if ($first === null) {
            return 0;
        }

        $start = $first->setTimezone($displayZone);
        $end = $now->setTimezone($displayZone);

        if ($end < $start) {
            return 0;
        }

        return $start->diff($end)->y;
    }

    /**
     * @param  list<AchievementFact>  $facts
     * @return iterable<AchievementFact>
     */
    private function matching(AchievementRule $rule, array $facts): iterable
    {
        $kind = $rule->evaluatedKind();

        foreach ($facts as $fact) {
            if ($fact->kind !== $kind) {
                continue;
            }

            if ($rule->role !== null && $fact->role !== $rule->role) {
                continue;
            }

            if ($rule->placementMax !== null && ($fact->placement === null || $fact->placement > $rule->placementMax)) {
                continue;
            }

            if ($rule->attributeKey !== null && ($fact->attributes[$rule->attributeKey] ?? null)?->id !== $rule->attributeValue) {
                continue;
            }

            yield $fact;
        }
    }
}
