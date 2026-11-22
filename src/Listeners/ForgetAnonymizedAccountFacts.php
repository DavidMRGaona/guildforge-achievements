<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use App\Domain\Events\UserAnonymized;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Achievements\Application\Services\ActivityLedgerInterface;
use Modules\Achievements\Application\Services\CommunityMetricsInterface;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;

/**
 * An anonymized account keeps no facts. The core's reader already leaves its facts out, so
 * neither a refresh nor a recalculation brings them back. A deactivated account keeps them.
 *
 * The people it shared subjects with may have counted it in a community achievement: their cached
 * progress is dropped, read while the facts still say who they are.
 *
 * On the queue, after the anonymization commits, so a database hiccup here never reaches it.
 */
final class ForgetAnonymizedAccountFacts implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        private readonly ActivityLedgerInterface $ledger,
        private readonly AchievementFactRepositoryInterface $facts,
        private readonly CommunityMetricsInterface $community,
    ) {}

    public function handle(UserAnonymized $event): void
    {
        $sharedWith = $this->facts->userIdsSharingSubjectsWith($event->userId);

        $this->ledger->forgetUser($event->userId);
        $this->community->forgetProgress([$event->userId, ...$sharedWith]);
    }
}
