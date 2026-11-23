<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Rules;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Achievements\Domain\Entities\AchievementFact;
use Modules\Achievements\Domain\ValueObjects\Season;

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
 * - people and mentor (community): the people the rule counts, which come from other people's
 *   facts and so from outside (`$people`: one instant per different person, when they were first
 *   met, or when a newcomer's first game was decided, MentorRule); without them, 0.
 * - recognitions: the recognitions received that count (`$people` again: one instant per
 *   recognition, already cut to what everybody else sees); without them, 0.
 * - manual: always 0, never met.
 *
 * Windows (RuleWindow), with days, months and years always as calendar dates in the display zone:
 * - season: only the facts of one season count; `$season` says which (the current one by default).
 * - streak: months in a row, each with at least `threshold` matching subjects; met once a run
 *   reaches `months`. Per season, a run never crosses from one season into the next. Its value
 *   (what progress shows) is the run going on now: the one that reaches the current month, or the
 *   previous one while the current month has nothing yet; 0 once a month was missed.
 *
 * metAt() says when the rule became met, from the facts themselves: the fact that reached the
 * threshold (in a streak, the one that completed its last month), or the first minute of the
 * anniversary. Whether a grant is announced is decided on it, never on the order in which
 * evaluations happen to run.
 */
final class RuleEvaluator
{
    /**
     * @param  list<AchievementFact>  $facts  the person's facts (other kinds are ignored)
     * @param  list<DateTimeImmutable>|null  $people  community rules: when each different person was first met; recognitions: when each one counted
     * @param  Season|null  $season  for a seasonal rule, the season asked about (the current one when null); ignored otherwise
     */
    public function isMet(AchievementRule $rule, array $facts, DateTimeImmutable $now, DateTimeZone $displayZone, ?array $people = null, ?Season $season = null): bool
    {
        return $this->metAt($rule, $facts, $now, $displayZone, $people, $season) instanceof DateTimeImmutable;
    }

    /**
     * How far the person is now, against AchievementRule::target(): in the current season for a
     * seasonal rule, the run going on for a streak.
     *
     * @param  list<AchievementFact>  $facts
     * @param  list<DateTimeImmutable>|null  $people  community rules: when each different person was first met; recognitions: when each one counted
     */
    public function value(AchievementRule $rule, array $facts, DateTimeImmutable $now, DateTimeZone $displayZone, ?array $people = null): int
    {
        $season = $rule->isSeasonal() ? Season::containing($now, $displayZone) : null;

        if ($rule->window->isStreak()) {
            return $this->currentRun($rule, $facts, $now, $displayZone, $season);
        }

        return $this->measure($rule, $this->inSeason($facts, $season, $displayZone), $now, $displayZone, $people);
    }

    /**
     * The metric over these facts, whatever the window.
     *
     * @param  list<AchievementFact>  $facts
     * @param  list<DateTimeImmutable>|null  $people
     */
    private function measure(AchievementRule $rule, array $facts, DateTimeImmutable $now, DateTimeZone $displayZone, ?array $people): int
    {
        return match ($rule->metric) {
            MetricType::People, MetricType::Mentor, MetricType::Recognitions => count($people ?? []),
            MetricType::Count => count($this->subjects($rule, $facts)),
            MetricType::Distinct => $this->distinctValues($rule, $facts),
            MetricType::Years => $rule->basis === YearsBasis::Account
                ? $this->accountYears($facts, $now, $displayZone)
                : count($this->subjects($rule, $facts)),
            MetricType::Manual => 0,
        };
    }

    /**
     * The seasons in which a seasonal rule has at least one matching fact, oldest first, up to the
     * current one (a fact dated ahead never opens a season still to come). Empty for any other rule.
     *
     * @param  list<AchievementFact>  $facts
     * @return list<Season>
     */
    public function seasons(AchievementRule $rule, array $facts, DateTimeImmutable $now, DateTimeZone $displayZone): array
    {
        if (! $rule->isSeasonal() || $rule->isManual()) {
            return [];
        }

        $current = Season::containing($now, $displayZone);
        $seasons = [];

        foreach ($this->matching($rule, $facts) as $fact) {
            $season = Season::containing($fact->occurredAt, $displayZone);

            if ($season->year <= $current->year) {
                $seasons[$season->year] = $season;
            }
        }

        ksort($seasons);

        return array_values($seasons);
    }

    /**
     * The instant the rule became met, or null while it is not (and for manual rules). For count and
     * distinct, the earliest instant by which `threshold` subjects (values) had a matching fact; for
     * a streak, the fact that completed the run's last month; for account years, the first minute of
     * the anniversary day in the display zone; for community rules, when the person who reached the
     * threshold was first met. A seasonal rule answers for `$season` (the current one when null).
     *
     * @param  list<AchievementFact>  $facts
     * @param  list<DateTimeImmutable>|null  $people  community rules: when each different person was first met; recognitions: when each one counted
     */
    public function metAt(AchievementRule $rule, array $facts, DateTimeImmutable $now, DateTimeZone $displayZone, ?array $people = null, ?Season $season = null): ?DateTimeImmutable
    {
        if ($rule->isManual()) {
            return null;
        }

        $season = $rule->isSeasonal() ? ($season ?? Season::containing($now, $displayZone)) : null;

        if ($rule->window->isStreak()) {
            return $this->streakMetAt($rule, $facts, $displayZone, $season);
        }

        $facts = $this->inSeason($facts, $season, $displayZone);

        if ($this->measure($rule, $facts, $now, $displayZone, $people) < $rule->threshold) {
            return null;
        }

        if ($rule->countsInstants()) {
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
     * The facts of one season (by their date in the display zone); every fact without a season.
     *
     * @param  list<AchievementFact>  $facts
     * @return list<AchievementFact>
     */
    private function inSeason(array $facts, ?Season $season, DateTimeZone $displayZone): array
    {
        if (! $season instanceof Season) {
            return $facts;
        }

        return array_values(array_filter($facts, static fn (AchievementFact $fact): bool => $season->contains($fact->occurredAt, $displayZone)));
    }

    /**
     * The months that reached the threshold, keyed by month number (year × 12 + month − 1, so
     * months in a row are consecutive numbers) and sorted, each with the instant it did: the
     * earliest fact of the `threshold`-th subject of that month.
     *
     * @param  list<AchievementFact>  $facts
     * @return array<int, DateTimeImmutable>
     */
    private function qualifyingMonths(AchievementRule $rule, array $facts, DateTimeZone $displayZone, ?Season $season): array
    {
        $firsts = [];

        foreach ($this->matching($rule, $facts) as $fact) {
            $local = $fact->occurredAt->setTimezone($displayZone);

            if ($season instanceof Season && (int) $local->format('Y') !== $season->year) {
                continue;
            }

            $month = $this->monthNumber($local);
            $subject = $fact->sourceType."\0".$fact->subjectId;

            if (! isset($firsts[$month][$subject]) || $fact->occurredAt < $firsts[$month][$subject]) {
                $firsts[$month][$subject] = $fact->occurredAt;
            }
        }

        $months = [];

        foreach ($firsts as $month => $subjects) {
            if (count($subjects) < $rule->threshold) {
                continue;
            }

            $instants = array_values($subjects);
            sort($instants);
            $months[$month] = $instants[$rule->threshold - 1];
        }

        ksort($months);

        return $months;
    }

    /**
     * @param  list<AchievementFact>  $facts
     */
    private function streakMetAt(AchievementRule $rule, array $facts, DateTimeZone $displayZone, ?Season $season): ?DateTimeImmutable
    {
        $needed = (int) $rule->window->months;
        $run = 0;
        $previous = null;

        foreach ($this->qualifyingMonths($rule, $facts, $displayZone, $season) as $month => $reachedAt) {
            $run = $previous !== null && $month === $previous + 1 ? $run + 1 : 1;
            $previous = $month;

            // Months are walked in order, so the first run to get there is the earliest
            if ($run >= $needed) {
                return $reachedAt;
            }
        }

        return null;
    }

    /**
     * The run of months going on now: it reaches the current month, or the previous one while the
     * current month has not reached the threshold yet.
     *
     * @param  list<AchievementFact>  $facts
     */
    private function currentRun(AchievementRule $rule, array $facts, DateTimeImmutable $now, DateTimeZone $displayZone, ?Season $season): int
    {
        $months = $this->qualifyingMonths($rule, $facts, $displayZone, $season);
        $month = $this->monthNumber($now->setTimezone($displayZone));

        if (! isset($months[$month])) {
            $month--;
        }

        $run = 0;

        while (isset($months[$month])) {
            $run++;
            $month--;
        }

        return $run;
    }

    private function monthNumber(DateTimeImmutable $local): int
    {
        return (int) $local->format('Y') * 12 + (int) $local->format('n') - 1;
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
