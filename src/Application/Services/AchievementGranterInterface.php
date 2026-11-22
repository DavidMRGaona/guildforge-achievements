<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\ValueObjects\NewGrant;

/**
 * The one way to grant an achievement: rules (this module's evaluator), manual and bulk grants (#4)
 * and challenges (#12) all come through here. A grant already held is not granted again (null), and
 * neither is one for an account that does not exist or is anonymized, or for an achievement that is
 * gone. A new grant dispatches AchievementGranted once its transaction commits.
 */
interface AchievementGranterInterface
{
    public function grant(NewGrant $grant): ?AchievementGrant;
}
