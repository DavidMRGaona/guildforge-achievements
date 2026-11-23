<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Support;

use App\Application\Authorization\Services\RoleGrantPolicy;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Auth;
use Modules\Achievements\Policies\AchievementPolicy;
use Throwable;

/**
 * Who sees an account's recognitions on the user page (#11): whoever holds
 * `achievements.moderate_recognitions` (no default role: it shows who played together) and may view
 * that account (the core's UserPolicy). Deleting one also needs to be able to manage that account
 * (the core's RoleGrantPolicy::canManageAccount(), as resetting a public profile does): a moderator
 * without administration permissions never acts on an administrator. Never for an anonymized
 * account. Asked on every request of the component and again when deleting; anything failing
 * hides it.
 */
final class RecognitionModerationAccess
{
    public static function allows(string $userId): bool
    {
        try {
            $viewer = Auth::user();

            if (! $viewer instanceof UserModel || ! AchievementPolicy::moderatesRecognitions($viewer)) {
                return false;
            }

            $account = UserModel::withTrashed()->find(strtolower($userId));

            return $account instanceof UserModel && $account->anonymized_at === null && $viewer->can('view', $account);
        } catch (Throwable) {
            return false;
        }
    }

    public static function allowsDeleting(string $userId): bool
    {
        try {
            $viewer = Auth::user();
            $account = UserModel::withTrashed()->find(strtolower($userId));

            return self::allows($userId)
                && $viewer instanceof UserModel
                && $account instanceof UserModel
                && app(RoleGrantPolicy::class)->canManageAccount($viewer, $account);
        } catch (Throwable) {
            return false;
        }
    }
}
