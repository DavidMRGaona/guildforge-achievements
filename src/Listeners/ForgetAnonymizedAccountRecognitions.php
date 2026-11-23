<?php

declare(strict_types=1);

namespace Modules\Achievements\Listeners;

use App\Domain\Events\UserAnonymized;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Achievements\Domain\Repositories\RecognitionRepositoryInterface;
use Modules\Achievements\Infrastructure\Recognitions\RecognitionNotices;

/**
 * An anonymized account keeps no recognitions, given or received, and no prompts (#11).
 */
final class ForgetAnonymizedAccountRecognitions implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        private readonly RecognitionRepositoryInterface $recognitions,
        private readonly RecognitionNotices $notices,
    ) {}

    public function handle(UserAnonymized $event): void
    {
        $this->notices->forget($this->recognitions->deleteForUser($event->userId));
    }
}
