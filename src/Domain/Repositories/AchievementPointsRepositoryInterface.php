<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

use DateTimeImmutable;
use Modules\Achievements\Domain\ValueObjects\PointsBreakdown;
use Modules\Achievements\Domain\ValueObjects\PointsPeriod;

/**
 * Points added up from the grants and the catalogue as they are now (#10): each grant gives the
 * points its achievement has. What the owner sees at once, and what the nightly photo keeps for
 * everybody else. Totals only: nothing here says which table, session or event a point came from.
 */
interface AchievementPointsRepositoryInterface
{
    /**
     * Each account's points over the period, by achievement, in one query, keyed by account id in
     * lower case; an account with none gets an empty breakdown.
     *
     * @param  list<string>  $userIds
     * @return array<string, PointsBreakdown>
     */
    public function breakdowns(array $userIds, PointsPeriod $period): array;

    /**
     * Every account with points over the period that still exists and is neither deactivated nor
     * anonymized, in one aggregate query: what the nightly photo keeps.
     *
     * @return array<string, PointsBreakdown> account id in lower case => its points
     */
    public function everyone(PointsPeriod $period): array;

    /**
     * The achievements that are secret now, as a set of ids in lower case.
     *
     * @return array<string, true>
     */
    public function secretIds(): array;

    /**
     * When the first grant still kept was made (storage zone), or null without any.
     */
    public function firstGrantedAt(): ?DateTimeImmutable;

    /**
     * The earliest season a grant was made for ('2025'), or null without any: a grant for the season
     * before, made in January, counts there.
     */
    public function earliestSeasonKey(): ?string;
}
