<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

use DateTimeImmutable;
use Modules\Achievements\Domain\ValueObjects\PointsBreakdown;

/**
 * The nightly photo of points (#10): what anybody but the owner sees, so points of people who
 * played together never rise at once in front of others. Only ids and points; period keys are `all`
 * or a season key.
 */
interface PointsSnapshotRepositoryInterface
{
    /**
     * Replaces the whole photo in one transaction and stamps when it was taken.
     *
     * @param  array<string, array<string, PointsBreakdown>>  $periods  period key => account id => points
     */
    public function replace(array $periods, DateTimeImmutable $takenAt): void;

    /**
     * When the photo was taken, or null while there is none.
     */
    public function takenAt(): ?DateTimeImmutable;

    /**
     * Every account in the photo for the period.
     *
     * @return array<string, PointsBreakdown>
     */
    public function forPeriod(string $periodKey): array;

    /**
     * The accounts asked, for the period; an account not in the photo is missing.
     *
     * @param  list<string>  $userIds
     * @return array<string, PointsBreakdown>
     */
    public function forUsers(array $userIds, string $periodKey): array;

    /**
     * Forgets an account's rows (anonymization).
     */
    public function deleteForUser(string $userId): void;
}
