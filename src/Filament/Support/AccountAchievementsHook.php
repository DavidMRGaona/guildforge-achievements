<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Support;

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Contracts\View\View;
use Livewire\Livewire;

/**
 * Draws the account's achievements and their history at the end of the core's user page: the core
 * has no slot for modules there, so it hangs from Filament's PAGE_END render hook, scoped to
 * EditUser, and reads the account from the page being rendered (also on its later Livewire requests,
 * so the components stay).
 */
final class AccountAchievementsHook
{
    public static function render(): View|string
    {
        $page = Livewire::current();

        if (! $page instanceof EditUser) {
            return '';
        }

        $account = $page->getRecord();

        if (! $account instanceof UserModel) {
            return '';
        }

        $userId = strtolower((string) $account->getKey());

        if (! AccountGrantsAccess::allows($userId)) {
            return '';
        }

        /** @var view-string $view */
        $view = 'achievements::filament.account-achievements';

        return view($view, ['userId' => $userId]);
    }
}
