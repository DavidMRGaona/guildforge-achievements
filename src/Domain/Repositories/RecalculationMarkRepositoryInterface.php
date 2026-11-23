<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

/**
 * Which activity sources were recalculated whole at least once (`achievements_recalculations`).
 */
interface RecalculationMarkRepositoryInterface
{
    public function markComplete(string $sourceType): void;

    /**
     * The source types among these that were recalculated whole at least once.
     *
     * @param  list<string>  $sourceTypes
     * @return list<string>
     */
    public function completedAmong(array $sourceTypes): array;
}
