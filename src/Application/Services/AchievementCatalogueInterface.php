<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Modules\Achievements\Application\DTOs\AchievementViewDTO;
use Modules\Achievements\Domain\Entities\Achievement;

/**
 * The catalogue as a person sees it: what every read outside the panel's management goes through
 * (pages and profile sections of #5, notices of #6). Secret achievements keep their name, look and
 * rule from whoever does not hold them.
 */
interface AchievementCatalogueInterface
{
    /**
     * Active achievements, plus the ones the viewer holds that were switched off since, with the
     * secret ones the viewer does not hold masked (a guest holds none); sorted by the name shown,
     * then id, so a masked one never sits where its real name would.
     *
     * @return list<AchievementViewDTO>
     */
    public function forViewer(?string $viewerId): array;

    public function present(Achievement $achievement, bool $held): AchievementViewDTO;
}
