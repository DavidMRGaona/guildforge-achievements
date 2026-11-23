<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Challenges;

use DateTimeImmutable;
use Modules\Achievements\Domain\Entities\AchievementFact;

/**
 * The total of a challenge from the facts of its dates. Pure: the facts (already within the dates)
 * and the members come from outside.
 *
 * A fact counts when it is of the rule's kind and passes every filter, as in an achievement's rule;
 * with a list of members, only theirs count. A person with several roles in one subject is one
 * participation. The challenge is complete at the fact that took the total to the target, so the
 * moment never depends on the order the facts came in.
 */
final class ChallengeCalculator
{
    /**
     * @param  list<AchievementFact>  $facts
     * @param  array<string, true>|null  $members  account ids (lower case) of the association members, or null when everybody counts
     */
    public function progress(ChallengeRule $rule, array $facts, ?array $members = null): ChallengeProgress
    {
        $counting = array_values(array_filter($facts, fn (AchievementFact $fact): bool => $this->counts($rule, $fact, $members)));
        usort($counting, static fn (AchievementFact $a, AchievementFact $b): int => [$a->occurredAt, $a->sourceType, $a->subjectId, strtolower($a->userId)]
            <=> [$b->occurredAt, $b->sourceType, $b->subjectId, strtolower($b->userId)]);

        $subjects = [];
        $participations = [];
        $people = [];
        $last = [];
        $completedAt = null;

        foreach ($counting as $fact) {
            $userId = strtolower($fact->userId);
            // Source types never hold a NUL, so the key never mixes two subjects
            $subject = $fact->sourceType."\0".$fact->subjectId;
            $subjects[$subject] = true;
            $participations[$subject."\0".$userId] = true;
            $people[$userId][$subject] = true;
            $last[$userId] = $fact->occurredAt;

            if ($completedAt === null && $this->total($rule->metric, $subjects, $participations, $people) >= $rule->target) {
                $completedAt = $fact->occurredAt;
            }
        }

        $contributions = array_map(count(...), $people);
        ksort($contributions, SORT_STRING);

        return new ChallengeProgress(
            total: $this->total($rule->metric, $subjects, $participations, $people),
            contributions: $contributions,
            lastContributions: $last,
            completedAt: $completedAt instanceof DateTimeImmutable ? $completedAt : null,
        );
    }

    /**
     * @param  array<string, true>  $subjects
     * @param  array<string, true>  $participations
     * @param  array<string, array<string, true>>  $people
     */
    private function total(ChallengeMetric $metric, array $subjects, array $participations, array $people): int
    {
        return match ($metric) {
            ChallengeMetric::Subjects => count($subjects),
            ChallengeMetric::Participations => count($participations),
            ChallengeMetric::People => count($people),
        };
    }

    /**
     * @param  array<string, true>|null  $members
     */
    private function counts(ChallengeRule $rule, AchievementFact $fact, ?array $members): bool
    {
        if ($fact->kind !== $rule->kind) {
            return false;
        }

        if ($members !== null && ! isset($members[strtolower($fact->userId)])) {
            return false;
        }

        if ($rule->role !== null && $fact->role !== $rule->role) {
            return false;
        }

        if ($rule->placementMax !== null && ($fact->placement === null || $fact->placement > $rule->placementMax)) {
            return false;
        }

        return $rule->attributeKey === null || ($fact->attributes[$rule->attributeKey] ?? null)?->id === $rule->attributeValue;
    }
}
