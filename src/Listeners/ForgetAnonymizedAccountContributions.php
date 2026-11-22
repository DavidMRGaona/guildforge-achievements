<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use App\Domain\Events\UserAnonymized;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Achievements\Domain\Repositories\ChallengeRepositoryInterface;

/**
 * An anonymized account leaves no trace of what it added to each challenge. The totals stay as
 * they were counted; the next count works them out from the ledger, which forgets that account's
 * facts too.
 */
final class ForgetAnonymizedAccountContributions implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        private readonly ChallengeRepositoryInterface $challenges,
    ) {}

    public function handle(UserAnonymized $event): void
    {
        $this->challenges->forgetUser($event->userId);
    }
}
