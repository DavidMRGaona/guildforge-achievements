<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use App\Domain\Activity\Events\ActivityRecorded;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Achievements\Application\Services\ActivityLedgerInterface;

/**
 * A subject may have changed: its facts are read again from the source and replace what the
 * ledger keeps. On the queue, after the change commits; a source that is missing or failed
 * leaves the ledger as it was.
 */
final class RecordActivityFacts implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    private const int LAST_BACKOFF = 120;

    public function __construct(
        private readonly ActivityLedgerInterface $ledger,
    ) {}

    public function handle(ActivityRecorded $event): void
    {
        $refresh = $this->ledger->refreshSubject($event->sourceType, $event->subjectId);

        // A failed source may be back soon; the rows stay as they are either way. Missing is not
        // retried: no module offers the type.
        if ($refresh->isFailed() && $this->job !== null && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? self::LAST_BACKOFF);
        }
    }
}
