<?php

declare(strict_types=1);

namespace Modules\Achievements\Policies;

use App\Infrastructure\Authorization\Policies\AuthorizesWithPermissions;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Achievements\Domain\Repositories\RecognitionRepositoryInterface;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\RecognitionTypeModel;

/**
 * Recognition types (#11) are content, like the catalogue: listed with `achievements.view_any` or
 * `achievements.manage`, written with `achievements.manage`.
 */
final class RecognitionTypePolicy
{
    use AuthorizesWithPermissions;

    public function viewAny(UserModel $user): bool
    {
        return $this->authorize($user, AchievementPolicy::VIEW_ANY) || $this->authorize($user, AchievementPolicy::MANAGE);
    }

    public function view(UserModel $user, RecognitionTypeModel $type): bool
    {
        return $this->viewAny($user);
    }

    public function create(UserModel $user): bool
    {
        return $this->authorize($user, AchievementPolicy::MANAGE);
    }

    public function update(UserModel $user, RecognitionTypeModel $type): bool
    {
        return $this->authorize($user, AchievementPolicy::MANAGE);
    }

    /**
     * Only a type nobody was given: one in use is switched off instead (the totals keep it).
     */
    public function delete(UserModel $user, RecognitionTypeModel $type): bool
    {
        return $this->authorize($user, AchievementPolicy::MANAGE) && ! app(RecognitionRepositoryInterface::class)->typeInUse($type->id);
    }

    public function deleteAny(UserModel $user): bool
    {
        return false;
    }

    public function forceDelete(UserModel $user, RecognitionTypeModel $type): bool
    {
        return false;
    }

    public function restore(UserModel $user, RecognitionTypeModel $type): bool
    {
        return false;
    }
}
