<?php

declare(strict_types=1);

namespace Modules\Achievements\Policies;

use App\Infrastructure\Authorization\Policies\AuthorizesWithPermissions;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;

/**
 * The catalogue: listed with `achievements.view_any` (or `manage`), written with `achievements.manage`.
 * Whoever only views sees secret achievements as «Logro secreto», without their rule.
 */
final class AchievementPolicy
{
    use AuthorizesWithPermissions;

    public const string VIEW_ANY = 'achievements:achievements.view_any';

    public const string MANAGE = 'achievements:achievements.manage';

    public function viewAny(UserModel $user): bool
    {
        return $this->authorize($user, self::VIEW_ANY) || $this->authorize($user, self::MANAGE);
    }

    public function view(UserModel $user, AchievementModel $achievement): bool
    {
        return $this->viewAny($user);
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
        return $this->authorize($user, self::MANAGE);
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
        return $this->authorize($user, self::MANAGE);
    }
}
