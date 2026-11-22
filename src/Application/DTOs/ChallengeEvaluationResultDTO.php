<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use Modules\Achievements\Application\Enums\ChallengeEvaluationOutcome;

/**
 * What a count of the challenges did: how many were counted, which of them are complete and how many
 * rewards were granted. `retry` lists challenges left as they were because something they need could
 * not be read now (the association's members).
 */
final readonly class ChallengeEvaluationResultDTO
{
    /**
     * @param  list<string>  $completed
     * @param  list<string>  $retry
     */
    public function __construct(
        public ChallengeEvaluationOutcome $outcome,
        public int $counted = 0,
        public array $completed = [],
        public int $granted = 0,
        public array $retry = [],
    ) {}

    public function isEvaluated(): bool
    {
        return $this->outcome === ChallengeEvaluationOutcome::Evaluated;
    }

    public function shouldRetry(): bool
    {
        return $this->outcome === ChallengeEvaluationOutcome::SettingsUnreadable || $this->retry !== [];
    }
}
