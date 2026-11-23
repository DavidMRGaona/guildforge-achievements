<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Achievements\Infrastructure\Points\PointsSnapshotTaker;

/**
 * The nightly photo of points (#10): every night after the rarity, and once when points are
 * switched on, so others see points from the start. Unique while it waits.
 */
final class TakePointsSnapshotJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 3600;

    public int $timeout = 900;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function handle(PointsSnapshotTaker $taker): void
    {
        $taker->take();
    }
}
