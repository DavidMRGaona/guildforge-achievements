<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Challenges;

use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Rules\AchievementRule;

/**
 * What an achievement must be to reward a challenge: switched on, not secret (a challenge names its
 * reward to everybody) and granted by hand only (so no rule hands it out on its own). Asked when the
 * reward is chosen, when the site shows the challenge and when the reward is granted: a reward that
 * stopped being one grants nothing and is not shown.
 */
final class ChallengeRewardRule
{
    public static function isUsable(Achievement $achievement): bool
    {
        return $achievement->active
            && ! $achievement->secret
            && $achievement->rule instanceof AchievementRule
            && $achievement->rule->isManual();
    }

    /**
     * The same, for the values a form is about to save.
     */
    public static function allows(bool $active, bool $secret, mixed $rule): bool
    {
        return $active && ! $secret && AchievementRule::tryFrom($rule)?->isManual() === true;
    }
}
