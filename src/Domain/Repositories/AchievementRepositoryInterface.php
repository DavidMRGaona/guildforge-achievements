<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

use Modules\Achievements\Domain\Entities\Achievement;

/**
 * The catalogue (`achievements_catalogue`). Reading never fails on a stored rule: one that cannot be
 * read comes with a null rule.
 */
interface AchievementRepositoryInterface
{
    /**
     * @return list<Achievement> by name
     */
    public function all(): array;

    /**
     * The achievements switched on in the catalogue, by name.
     *
     * @return list<Achievement>
     */
    public function active(): array;

    public function find(string $id): ?Achievement;
}
