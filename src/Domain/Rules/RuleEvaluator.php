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
 * - years, account: full years from the day of the earliest account fact to today, both as
 *   calendar dates in the display zone (the anniversary counts from its first minute); membership:
 *   membership year subjects.
 * - people (community): the people the rule counts, which come from other people's
 *   facts and so from outside (`$people`: one instant per different person, when they were first
 *   met); without them, 0.
 * - manual: always 0, never met.
 *
 * metAt() says when the rule became met, from the facts themselves: the fact that reached the
 * threshold, or the first minute of the anniversary. Whether a grant is announced is decided on it,
 * never on the order in which evaluations happen to run.
 */
final class RuleEvaluator
{
    /**
     * @param  list<AchievementFact>  $facts  the person's facts (other kinds are ignored)
     * @param  list<DateTimeImmutable>|null  $people  community rules: when each different person was first met
     */
    public function isMet(AchievementRule $rule, array $facts, DateTimeImmutable $now, DateTimeZone $displayZone, ?array $people = null): bool
    {
        return ! $rule->isManual() && $this->value($rule, $facts, $now, $displayZone, $people) >= $rule->threshold;
    }

    /**
     * @param  list<AchievementFact>  $facts
     * @param  list<DateTimeImmutable>|null  $people  community rules: when each different person was first met
     */
    public function value(AchievementRule $rule, array $facts, DateTimeImmutable $now, DateTimeZone $displayZone, ?array $people = null): int
    {
        return match ($rule->metric) {
            MetricType::People => count($people ?? []),
            MetricType::Count => count($this->subjects($rule, $facts)),
            MetricType::Distinct => $this->distinctValues($rule, $facts),
            MetricType::Years => $rule->basis === YearsBasis::Account
                ? $this->accountYears($facts, $now, $displayZone)
                : count($this->subjects($rule, $facts)),
            MetricType::Manual => 0,
        };
    }

    /**
     * The instant the rule became met, or null while it is not (and for manual rules). For count and
     * distinct, the earliest instant by which `threshold` subjects (values) had a matching fact; for
     * account years, the first minute of the anniversary day in the display zone; for community
     * rules, when the person who reached the threshold was first met.
     *
     * @param  list<AchievementFact>  $facts
     * @param  list<DateTimeImmutable>|null  $people  community rules: when each different person was first met
     */
    public function metAt(AchievementRule $rule, array $facts, DateTimeImmutable $now, DateTimeZone $displayZone, ?array $people = null): ?DateTimeImmutable
    {
        if (! $this->isMet($rule, $facts, $now, $displayZone, $people)) {
            return null;
        }

        if ($rule->isCommunity()) {
            $met = $people ?? [];
            sort($met);

            return $met[$rule->threshold - 1] ?? null;
        }

        if ($rule->metric === MetricType::Years && $rule->basis === YearsBasis::Account) {
            $first = $this->firstAccountFact($facts);

            return $first?->setTimezone($displayZone)->setTime(0, 0)->modify('+'.$rule->threshold.' years');
        }

        $matching = iterator_to_array($this->matching($rule, $facts), false);
        usort($matching, static fn (AchievementFact $a, AchievementFact $b): int => $a->occurredAt <=> $b->occurredAt);
        $seen = [];

        foreach ($matching as $fact) {
            $key = $rule->metric === MetricType::Distinct
                ? ($rule->distinctKey === null ? null : ($fact->attributes[$rule->distinctKey] ?? null)?->id)
                : $fact->sourceType."\0".$fact->subjectId;

            if ($key === null || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if (count($seen) >= $rule->threshold) {
                return $fact->occurredAt;
            }
        }

        return null;
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
        $first = $this->firstAccountFact($facts);

        if (! $first instanceof DateTimeImmutable) {
            return 0;
        }

        // Calendar dates, not instants: a nightly run on the anniversary counts it, whatever the hour
        $start = $first->setTimezone($displayZone)->setTime(0, 0);
        $end = $now->setTimezone($displayZone)->setTime(0, 0);

        if ($end < $start) {
            return 0;
        }

        return $start->diff($end)->y;
    }

    /**
     * @param  list<AchievementFact>  $facts
     */
    private function firstAccountFact(array $facts): ?DateTimeImmutable
    {
        $first = null;

        foreach ($facts as $fact) {
            if ($fact->kind === YearsBasis::Account->kind() && ($first === null || $fact->occurredAt < $first)) {
                $first = $fact->occurredAt;
            }
        }

        return $first;
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
