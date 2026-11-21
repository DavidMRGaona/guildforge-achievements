<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use App\Domain\Events\UserAnonymized;
use Modules\Achievements\Application\Services\ActivityLedgerInterface;

/**
 * An anonymized account keeps no facts. The core's reader already leaves its facts out, so
 * neither a refresh nor a recalculation brings them back. A deactivated account keeps them.
 */
final readonly class ForgetAnonymizedAccountFacts
{
    public function __construct(
        private ActivityLedgerInterface $ledger,
    ) {}

    public function handle(UserAnonymized $event): void
    {
        $this->ledger->forgetUser($event->userId);
    }
}
