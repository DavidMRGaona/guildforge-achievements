<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Achievements\Application\Services\AchievementRarityInterface;

/**
 * Computes the rarity again: every night (people stop being active with the date alone) and after
 * grants. Unique while it waits, so an evaluation granting hundreds of achievements queues one; a
 * grant made while it runs queues the next.
 */
final class RefreshAchievementRarityJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function handle(AchievementRarityInterface $rarity): void
    {
        $rarity->refresh();
    }
}
