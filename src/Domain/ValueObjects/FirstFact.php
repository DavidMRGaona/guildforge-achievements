<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

use DateTimeImmutable;

/**
 * An account's earliest fact of a kind in one role (their first game as a player, say): when it
 * happened and who ran each subject they had a fact in at that instant (usually one; two tables
 * starting at once give two).
 */
final readonly class FirstFact
{
    /**
     * @param  list<SubjectHosts>  $subjects
     */
    public function __construct(
        public string $userId,
        public DateTimeImmutable $occurredAt,
        public array $subjects,
    ) {}
}
