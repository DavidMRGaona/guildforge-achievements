<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use App\Domain\Events\UserAnonymized;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Achievements\Application\Services\ActivityLedgerInterface;

/**
 * An anonymized account keeps no facts. The core's reader already leaves its facts out, so
 * neither a refresh nor a recalculation brings them back. A deactivated account keeps them.
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
    ) {}

    public function handle(UserAnonymized $event): void
    {
        $this->ledger->forgetUser($event->userId);
    }
}
