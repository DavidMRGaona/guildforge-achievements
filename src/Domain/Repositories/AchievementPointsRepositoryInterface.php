<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

use DateTimeImmutable;
use Modules\Achievements\Domain\ValueObjects\PointsBreakdown;
use Modules\Achievements\Domain\ValueObjects\PointsPeriod;

/**
 * Points added up from the grants and the catalogue (#10): each grant gives the points its
 * achievement has now. Secret achievements are kept apart (PointsBreakdown), so whoever is not the
 * owner gets only the ones revealed to them. Totals only: nothing here says which table, session or
 * event a point came from.
 */
interface AchievementPointsRepositoryInterface
{
    /**
     * Each account's points over the period, in one query, keyed by account id in lower case; an
     * account with none gets an empty breakdown.
     *
     * @param  list<string>  $userIds
     * @return array<string, PointsBreakdown>
     */
    public function breakdowns(array $userIds, PointsPeriod $period): array;

    /**
     * Every account with points over the period that may appear in a leaderboard as far as the
     * module knows: an account that exists, is neither deactivated nor anonymized and has not hidden
     * its achievements or opted out. One aggregate query. Who the viewer may see, and what of each
     * total they may see, is decided afterwards, never here.
     *
     * @return array<string, PointsBreakdown> account id in lower case => its points
     */
    public function standings(PointsPeriod $period): array;

    /**
     * When the first grant still kept was made (storage zone), or null without any.
     */
    public function firstGrantedAt(): ?DateTimeImmutable;
}
