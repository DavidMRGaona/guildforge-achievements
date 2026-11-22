<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Someone took an achievement away from an account, by hand. Ids only; dispatched once the revoke is
 * committed, so never inside the impersonation safety net.
 */
final readonly class AchievementRevoked implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $grantId,
        public string $userId,
        public string $achievementId,
        public ?string $season,
    ) {}
}
