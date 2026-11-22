<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use Modules\Achievements\Application\Enums\EvaluationOutcome;

/**
 * What an evaluation did: the grants it made (in a dry run, the ones it would make), the accounts it
 * looked at and the achievements it evaluated.
 */
final readonly class EvaluationResultDTO
{
    /**
     * @param  list<GrantedAchievementDTO>  $granted
     */
    public function __construct(
        public EvaluationOutcome $outcome,
        public array $granted = [],
        public int $users = 0,
        public int $achievements = 0,
    ) {}

    public static function settingsUnreadable(): self
    {
        return new self(EvaluationOutcome::SettingsUnreadable);
    }

    public function isEvaluated(): bool
    {
        return $this->outcome === EvaluationOutcome::Evaluated;
    }
}
