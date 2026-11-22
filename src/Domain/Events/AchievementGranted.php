<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Somebody got an achievement they did not hold. Dispatched once per grant, after the grant
 * commits (never inside the impersonation safety net, which never commits); never for one
 * already held. Internal to the module: the notices (#6) listen to it and
 * send nothing for a silent grant. Ids only.
 */
final readonly class AchievementGranted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $grantId,
        public string $userId,
        public string $achievementId,
        public string $source,
        public bool $silent,
        public ?string $season = null,
    ) {}
}
