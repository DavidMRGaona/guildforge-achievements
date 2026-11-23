<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Points;

use Illuminate\Support\Facades\Date;
use Modules\Achievements\Application\Services\AchievementPointsInterface;
use Modules\Achievements\Domain\Repositories\AchievementPointsRepositoryInterface;
use Modules\Achievements\Domain\Repositories\PointsSnapshotRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\PointsPeriod;

/**
 * Takes the nightly photo of points (#10): every account's points by achievement, all time and for
 * each season on offer, replaced whole. Only ids and points; nothing about who may see whom or what
 * is secret, which is decided when it is read.
 */
final readonly class PointsSnapshotTaker
{
    public function __construct(
        private AchievementPointsRepositoryInterface $points,
        private PointsSnapshotRepositoryInterface $snapshots,
        private AchievementPointsInterface $seasons,
    ) {}

    public function take(): void
    {
        $periods = [PointsPeriod::allTime()->key() => $this->points->everyone(PointsPeriod::allTime())];

        foreach ($this->seasons->seasons() as $season) {
            $period = $this->seasons->period($season);
            $periods[$period->key()] = $this->points->everyone($period);
        }

        $this->snapshots->replace($periods, Date::now()->toDateTimeImmutable());
    }
}
