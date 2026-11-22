<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\ValueObjects\NewGrant;

/**
 * The one way to grant an achievement: rules (this module's evaluator), manual and bulk grants (#4)
 * and challenges (#12) all come through here. A grant already held is not granted again (null), and
 * neither is one for an account that does not exist or is anonymized, or for an achievement that is
 * gone. A new grant dispatches AchievementGranted once its transaction commits. Every change is
 * written to the grant history in the same transaction.
 *
 * Grants are permanent: revoke() is the one way to take one away, by a person and with a reason.
 * What was revoked is never granted again by a rule, a challenge or in bulk; only a grant by hand
 * to that account brings it back.
 */
interface AchievementGranterInterface
{
    public function grant(NewGrant $grant): ?AchievementGrant;

    /**
     * Null when the grant is gone (revoked meanwhile) or the reason is blank. Dispatches
     * AchievementRevoked once committed.
     */
    public function revoke(string $grantId, string $actorId, string $reason): ?AchievementGrant;
}
