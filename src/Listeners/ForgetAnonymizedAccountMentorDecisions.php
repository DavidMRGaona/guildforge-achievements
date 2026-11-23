<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use App\Domain\Events\UserAnonymized;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Achievements\Domain\Repositories\MentorDecisionRepositoryInterface;

/**
 * An anonymized newcomer's decision is erased (who ran their first game is not kept), and they are
 * never decided again, since anonymized accounts are never listed. Where the account was the
 * mentor, the newcomer stays decided with nobody, so nobody else becomes their mentor.
 */
final class ForgetAnonymizedAccountMentorDecisions implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        private readonly MentorDecisionRepositoryInterface $decisions,
    ) {}

    public function handle(UserAnonymized $event): void
    {
        $this->decisions->forgetAccount($event->userId);
    }
}
