<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

final readonly class GrantedAchievementDTO
{
    public function __construct(
        public string $userId,
        public string $achievementId,
    ) {}
}
