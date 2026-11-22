<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

/**
 * What replacing a subject's facts did: who took part before and after (sorted account ids) and
 * whether any row changed. Everybody on either side is affected: someone who left may lose
 * progress, someone new may earn an achievement.
 */
final readonly class FactsReplacement
{
    /**
     * @param  list<string>  $userIdsBefore
     * @param  list<string>  $userIdsAfter
     */
    public function __construct(
        public array $userIdsBefore,
        public array $userIdsAfter,
        public bool $changed,
    ) {}

    /**
     * @return list<string>
     */
    public function affectedUserIds(): array
    {
        $ids = array_values(array_unique([...$this->userIdsBefore, ...$this->userIdsAfter]));
        sort($ids, SORT_STRING);

        return $ids;
    }
}
