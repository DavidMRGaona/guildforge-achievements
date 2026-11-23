<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

use DateTimeImmutable;
use Modules\Achievements\Domain\ValueObjects\PointsBreakdown;
use Modules\Achievements\Domain\ValueObjects\PointsPeriod;

/**
 * Points added up from the grants and the catalogue as they are now (#10): each grant gives the
 * points its achievement has now. The owner's own are read in full; anybody else's only with the
 * grants made before the cut (PointsCutoffInterface), passed as `$before`. Totals only: nothing here says which table, session or event a point came from.
 */
interface AchievementPointsRepositoryInterface
{
    /**
     * Each account's points over the period, by achievement, in one query, keyed by account id in
     * lower case; an account with none gets an empty breakdown. With `$before`, only grants made
     * before that instant.
     *
     * @param  list<string>  $userIds
     * @return array<string, PointsBreakdown>
     */
    public function breakdowns(array $userIds, PointsPeriod $period, ?DateTimeImmutable $before = null): array;

    /**
     * Every account with points over the period that still exists and is neither deactivated nor
     * anonymized, in one aggregate query; with `$before`, only grants made before that instant.
     *
     * @return array<string, PointsBreakdown> account id in lower case => its points
     */
    public function everyone(PointsPeriod $period, ?DateTimeImmutable $before = null): array;

    /**
     * The achievements that exist now and are not secret, as a set of ids in lower case.
     *
     * @return array<string, true>
     */
    public function openIds(): array;

    /**
     * When the first grant still kept of an account that still exists, not deactivated or anonymized,
     * was made (storage zone), or null without any; with `$before`, among grants made before that
     * instant.
     */
    public function firstGrantedAt(?DateTimeImmutable $before = null): ?DateTimeImmutable;

    /**
     * The earliest season a grant of such an account was made for ('2025'), or null without any: a
     * grant for the season before, made in January, counts there.
     */
    public function earliestSeasonKey(?DateTimeImmutable $before = null): ?string;
}
