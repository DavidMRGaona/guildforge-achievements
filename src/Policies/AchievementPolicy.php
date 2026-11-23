<?php

declare(strict_types=1);

namespace Modules\Achievements\Policies;

use App\Infrastructure\Authorization\Policies\AuthorizesWithPermissions;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\ChallengeModel;

/**
 * The catalogue: listed with `achievements.view_any`, `manage` or `grant` (granting needs seeing what
 * there is to grant), written with `achievements.manage`. Whoever does not manage it sees secret
 * achievements as «Logro secreto», without their rule, and may neither open, grant nor revoke them.
 * Grants are permanent, so an achievement somebody holds is never deleted, only switched off (the
 * database refuses it too).
 *
 * Grants (#4): `achievements.grant` grants by hand or in bulk (an achievement switched on, and for a
 * season or a streak only while seasons and streaks are on) and revokes (switched on or off). Who holds an achievement and its history are seen with `grant` or `manage`,
 * never with `view_any` alone.
 */
final class AchievementPolicy
{
    use AuthorizesWithPermissions;

    public const string VIEW_ANY = 'achievements:achievements.view_any';

    public const string MANAGE = 'achievements:achievements.manage';

    public const string GRANT = 'achievements:achievements.grant';

    public function viewAny(UserModel $user): bool
    {
        return $this->authorize($user, self::VIEW_ANY)
            || $this->authorize($user, self::MANAGE)
            || $this->authorize($user, self::GRANT);
    }

    public function view(UserModel $user, AchievementModel $achievement): bool
    {
        return $this->viewAny($user) && $this->seesUnmasked($user, $achievement);
    }

    public function grant(UserModel $user, AchievementModel $achievement): bool
    {
        return $achievement->is_active
            && $this->authorize($user, self::GRANT)
            && $this->seesUnmasked($user, $achievement)
            && ! $this->seasonalWhileOff($achievement);
    }

    /**
     * A season or a streak while seasons and streaks are switched off: the mechanic is off, so no
     * season of it is granted, by hand either (what was granted stays, and can still be revoked).
     */
    private function seasonalWhileOff(AchievementModel $achievement): bool
    {
        $rule = AchievementRule::tryFrom($achievement->getRawOriginal('rule'));

        return $rule instanceof AchievementRule
            && ! $rule->window->isAllTime()
            && ! app(AchievementsSettingsInterface::class)->seasonsAndStreaksEnabled();
    }

    public function revoke(UserModel $user, AchievementModel $achievement): bool
    {
        return $this->authorize($user, self::GRANT) && $this->seesUnmasked($user, $achievement);
    }

    public function viewGrants(UserModel $user, AchievementModel $achievement): bool
    {
        return self::seesGrants($user) && $this->view($user, $achievement);
    }

    /**
     * Whether someone may grant and revoke achievements at all (each one is still asked with grant()
     * and revoke()).
     */
    public static function grantsAny(UserModel $user): bool
    {
        return (new self)->authorize($user, self::GRANT);
    }

    /**
     * Whether someone sees who holds achievements at all (the account page, the holders lists).
     */
    public static function seesGrants(UserModel $user): bool
    {
        $policy = new self;

        return $policy->authorize($user, self::GRANT) || $policy->authorize($user, self::MANAGE);
    }

    /**
     * Whether someone sees this achievement as it is: any achievement but a secret one, which only
     * whoever manages the catalogue sees.
     */
    public static function seesUnmaskedFor(UserModel $user, AchievementModel $achievement): bool
    {
        return (new self)->seesUnmasked($user, $achievement);
    }

    private function seesUnmasked(UserModel $user, AchievementModel $achievement): bool
    {
        return ! $achievement->is_secret || $this->authorize($user, self::MANAGE);
    }

    public function create(UserModel $user): bool
    {
        return $this->authorize($user, self::MANAGE);
    }

    public function update(UserModel $user, AchievementModel $achievement): bool
    {
        return $this->authorize($user, self::MANAGE);
    }

    public function delete(UserModel $user, AchievementModel $achievement): bool
    {
        // The reward of a challenge stays while the challenge does (#12)
        return $this->authorize($user, self::MANAGE)
            && ! $achievement->grants()->exists()
            && ! ChallengeModel::query()->where('reward_achievement_id', $achievement->id)->exists();
    }

    public function deleteAny(UserModel $user): bool
    {
        return $this->authorize($user, self::MANAGE);
    }

    public function restore(UserModel $user, AchievementModel $achievement): bool
    {
        return $this->authorize($user, self::MANAGE);
    }

    public function forceDelete(UserModel $user, AchievementModel $achievement): bool
    {
        return $this->delete($user, $achievement);
    }
}
