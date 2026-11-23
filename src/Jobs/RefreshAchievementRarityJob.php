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
 * Takes the nightly photo (`achievements:rarity`, 03:30 in the display zone): the rarity of every
 * achievement and the instant up to which grants are shown to anybody but their owner. Only at
 * night, never after a grant or a revoke: what others see changes once a day, so two people who play
 * together never give themselves away by their totals rising at once.
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
