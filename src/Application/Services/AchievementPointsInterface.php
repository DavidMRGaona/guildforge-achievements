<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Modules\Achievements\Application\DTOs\PointsSummaryDTO;
use Modules\Achievements\Domain\ValueObjects\PointsPeriod;

/**
 * Points and levels (#10). Bound "scoped": each account's points are read once per request or job.
 */
interface AchievementPointsInterface
{
    /**
     * The season of today, in the display zone.
     */
    public function currentSeason(): int;

    /**
     * All time (null), or that season with its bounds.
     */
    public function period(?int $season): PointsPeriod;

    /**
     * The seasons with grants, from the current one back to the first grant's.
     *
     * @return list<int>
     */
    public function seasons(): array;

    /**
     * One account's points as a viewer sees them (null: a guest); the season while seasons are on,
     * the level while levels show. The owner sees every point; anybody else not those of the secret
     * achievements masked to them, which are only ever counted.
     */
    public function summary(string $userId, ?string $viewerId): PointsSummaryDTO;

    /**
     * The achievements an account holds (any season), as a set of ids in lower case.
     *
     * @return array<string, true>
     */
    public function heldBy(string $viewerId): array;
}
