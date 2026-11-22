<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use App\Domain\Events\UserAnonymized;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;

/**
 * An anonymized account holds no achievements. A grant being written while it is anonymized waits
 * for the account's row (shared lock), so it either sees the account anonymized or is written
 * before this runs.
 */
final class ForgetAnonymizedAccountGrants implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        private readonly AchievementGrantRepositoryInterface $grants,
    ) {}

    public function handle(UserAnonymized $event): void
    {
        $this->grants->deleteForUser($event->userId);
    }
}
