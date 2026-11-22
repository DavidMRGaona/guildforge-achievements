<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use App\Domain\Events\UserAnonymized;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;

/**
 * An anonymized account keeps no choices about its achievements.
 */
final class ForgetAnonymizedAccountPreferences implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        private readonly AchievementPreferencesRepositoryInterface $preferences,
    ) {}

    public function handle(UserAnonymized $event): void
    {
        $this->preferences->deleteForUser($event->userId);
    }
}
