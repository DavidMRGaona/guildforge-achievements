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
     * The seasons with grants, from the current one back to the earliest of the first grant's and
     * the earliest season a grant was made for.
     *
     * @return list<int>
     */
    public function seasons(): array;

    /**
     * One account's points as a viewer sees them (null: a guest); the season while seasons are on,
     * the level while levels show. The owner sees their own at once and in full; anybody else last
     * night's photo, without the secret achievements masked to them, which are only ever counted.
     * Null for anybody else while there is no photo yet.
     */
    public function summary(string $userId, ?string $viewerId): ?PointsSummaryDTO;

    /**
     * The achievements that are secret now, as a set of ids in lower case (read once per request).
     *
     * @return array<string, true>
     */
    public function secretIds(): array;

    /**
     * The secret achievements of an owner revealed to a viewer: those the owner featured (featuring
     * reveals one to everybody) and those the viewer holds too.
     *
     * @return array<string, true>
     */
    public function revealedSecrets(string $ownerId, ?string $viewerId): array;

    /**
     * The achievements an account holds (any season), as a set of ids in lower case.
     *
     * @return array<string, true>
     */
    public function heldBy(string $viewerId): array;
}
