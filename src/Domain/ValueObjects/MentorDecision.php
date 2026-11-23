<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

use DateTimeImmutable;

/**
 * Who a newcomer's mentor is, decided once (MentorRule): null when there was none. `decidedAt` is
 * when their first game could be decided on (the game plus the grace), the instant the mentor rule
 * counts.
 */
final readonly class MentorDecision
{
    public function __construct(
        public string $newcomerId,
        public ?string $mentorId,
        public DateTimeImmutable $decidedAt,
    ) {}
}
