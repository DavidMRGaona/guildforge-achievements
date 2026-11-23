<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use App\Domain\Events\UserAnonymized;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Achievements\Domain\Repositories\PointsSnapshotRepositoryInterface;

/**
 * An anonymized account leaves the photo of points at once, not the next night (#10).
 */
final class ForgetAnonymizedAccountPoints implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        private readonly PointsSnapshotRepositoryInterface $snapshots,
    ) {}

    public function handle(UserAnonymized $event): void
    {
        $this->snapshots->deleteForUser($event->userId);
    }
}
