<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use DateTimeImmutable;

/**
 * Up to when anybody but the owner sees grants (#10): points and levels of others count only grants
 * made before this instant, so totals of two people who played together never rise at once in front
 * of others. Everything that takes away or masks (a revoke, an achievement made secret, a hidden
 * profile, a change of points in the catalogue) applies at once: it is read live, before the cut.
 * Null while there is none: others see no points then, never a 0.
 */
interface PointsCutoffInterface
{
    public function takenAt(): ?DateTimeImmutable;
}
