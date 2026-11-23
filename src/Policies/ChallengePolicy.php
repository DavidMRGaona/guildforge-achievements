<?php

declare(strict_types=1);

namespace Modules\Achievements\Policies;

use App\Infrastructure\Authorization\Policies\AuthorizesWithPermissions;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\ChallengeModel;

/**
 * Collective challenges (#12): listed with `achievements.view_any` or `achievements.manage`, created,
 * edited and closed with `achievements.manage` (content, like the catalogue). A closed challenge is
 * never edited nor opened again; a complete one keeps what it counts, its days, who takes part and
 * its reward. Only a challenge that never completed is deleted: once complete,
 * its reward is permanent and the challenge is the record of why.
 */
final class ChallengePolicy
{
    use AuthorizesWithPermissions;

    public function viewAny(UserModel $user): bool
    {
        return $this->authorize($user, AchievementPolicy::VIEW_ANY) || $this->authorize($user, AchievementPolicy::MANAGE);
    }

    public function view(UserModel $user, ChallengeModel $challenge): bool
    {
        return $this->viewAny($user);
    }

    public function create(UserModel $user): bool
    {
        return $this->authorize($user, AchievementPolicy::MANAGE);
    }

    public function update(UserModel $user, ChallengeModel $challenge): bool
    {
        return $challenge->closed_at === null && $this->authorize($user, AchievementPolicy::MANAGE);
    }

    /**
     * What it counts, its days, who takes part and its reward: only while it is not complete (its
     * rewards were granted for exactly that). Its name and description stay editable.
     */
    public function changeWhatCounts(UserModel $user, ChallengeModel $challenge): bool
    {
        return $challenge->completed_at === null && $this->update($user, $challenge);
    }

    public function close(UserModel $user, ChallengeModel $challenge): bool
    {
        return $challenge->closed_at === null && $this->authorize($user, AchievementPolicy::MANAGE);
    }

    public function delete(UserModel $user, ChallengeModel $challenge): bool
    {
        return $challenge->completed_at === null && $this->authorize($user, AchievementPolicy::MANAGE);
    }

    public function deleteAny(UserModel $user): bool
    {
        return false;
    }

    public function forceDelete(UserModel $user, ChallengeModel $challenge): bool
    {
        return false;
    }

    public function restore(UserModel $user, ChallengeModel $challenge): bool
    {
        return false;
    }
}
