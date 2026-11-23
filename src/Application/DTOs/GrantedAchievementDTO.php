<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

/**
 * One grant an evaluation made (or, in a dry run, would make). `season` is the key of the season
 * of a repeatable achievement («2027»), null for the rest.
 */
final readonly class GrantedAchievementDTO
{
    public function __construct(
        public string $userId,
        public string $achievementId,
        public bool $silent = true,
        public ?string $season = null,
    ) {}
}
