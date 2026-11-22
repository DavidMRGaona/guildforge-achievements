<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Support;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Auth;
use Modules\Achievements\Policies\AchievementPolicy;
use Throwable;

/**
 * Who sees an account's achievements and their history on the user page: whoever may grant or
 * manage achievements and may view that account (the core's UserPolicy). Never for an anonymized
 * account (it holds nothing). Asked on every request of the components; anything failing hides them.
 */
final class AccountGrantsAccess
{
    public static function allows(string $userId): bool
    {
        try {
            $viewer = Auth::user();

            if (! $viewer instanceof UserModel || ! AchievementPolicy::seesGrants($viewer)) {
                return false;
            }

            $account = UserModel::withTrashed()->find(strtolower($userId));

            return $account instanceof UserModel && $account->anonymized_at === null && $viewer->can('view', $account);
        } catch (Throwable) {
            return false;
        }
    }
}
