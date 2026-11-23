<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Challenges;

use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Rules\AchievementRule;

/**
 * What an achievement must be to reward a challenge: switched on, not secret (a challenge names its
 * reward to everybody), granted by hand only (so no rule hands it out on its own) and not repeated
 * each season (a challenge grants it once, without a season). Asked when the
 * reward is chosen, when the site shows the challenge and when the reward is granted: a reward that
 * stopped being one grants nothing and is not shown.
 */
final class ChallengeRewardRule
{
    public static function isUsable(Achievement $achievement): bool
    {
        return $achievement->active
            && ! $achievement->secret
            && self::isRewardRule($achievement->rule);
    }

    /**
     * The same, for the values a form is about to save.
     */
    public static function allows(bool $active, bool $secret, mixed $rule): bool
    {
        return $active && ! $secret && self::isRewardRule(AchievementRule::tryFrom($rule));
    }

    /**
     * Granted by hand only and once: a reward of a season would be granted without one by the
     * challenge, and a season revoked by hand would come back that way.
     */
    private static function isRewardRule(?AchievementRule $rule): bool
    {
        return $rule instanceof AchievementRule && $rule->isManual() && ! $rule->isSeasonal();
    }
}
