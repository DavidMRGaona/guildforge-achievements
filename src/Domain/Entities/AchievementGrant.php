<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Entities;

use DateTimeImmutable;
use Modules\Achievements\Domain\Enums\GrantSource;

/**
 * One person holding one achievement (for one season, when it repeats). `grantedAt` is an instant
 * in the storage zone; `actorId` is who granted it by hand; `silent` grants never send a notice.
 */
final readonly class AchievementGrant
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $achievementId,
        public ?string $season,
        public DateTimeImmutable $grantedAt,
        public GrantSource $source,
        public ?string $actorId,
        public bool $silent,
    ) {}
}
