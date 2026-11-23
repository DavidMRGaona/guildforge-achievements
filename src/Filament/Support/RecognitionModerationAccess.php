<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Support;

use App\Application\Authorization\Services\RoleGrantPolicy;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Auth;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\RecognitionModel;
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

    /**
     * One recognition from the page of `$userId`: it must be one of that account's, and the
     * moderator must be able to manage both people, since deleting it changes what each of them
     * gave or received (an administrator's received recognitions are never touched from a member's
     * page). A person who no longer exists leaves only the other to check.
     */
    public static function allowsDeletingRecord(string $userId, RecognitionModel $record): bool
    {
        return self::isOnPage($userId, $record)
            && self::allowsDeleting($userId)
            && self::mayManageOther($userId, $record);
    }

    /**
     * Whether the recognition is one the account of the page gave or received.
     */
    public static function isOnPage(string $userId, RecognitionModel $record): bool
    {
        $userId = strtolower($userId);

        return strtolower($record->giver_id) === $userId || strtolower($record->receiver_id) === $userId;
    }

    /**
     * Whether the moderator may manage the other person of the row, read from the relations the
     * table already loaded (`with()`). A person who no longer exists leaves nothing to check.
     */
    public static function mayManageOther(string $userId, RecognitionModel $record): bool
    {
        try {
            $viewer = Auth::user();
            $other = self::otherOf($userId, $record);

            return $viewer instanceof UserModel
                && (! $other instanceof UserModel || app(RoleGrantPolicy::class)->canManageAccount($viewer, $other));
        } catch (Throwable) {
            return false;
        }
    }

    public static function otherOf(string $userId, RecognitionModel $record): ?UserModel
    {
        return strtolower($record->giver_id) === strtolower($userId) ? $record->receiver : $record->giver;
    }
}
