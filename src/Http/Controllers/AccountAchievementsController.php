<?php

declare(strict_types=1);

namespace Modules\Achievements\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Http\Requests\UpdateAccountAchievementsRequest;

/**
 * Saves the signed-in account's choices from the «Logros» tab and goes back to it. A choice whose
 * mechanic is off is not offered, so it stays as it was whatever is sent; what the profile section
 * chose (hidden, featured) is kept.
 */
final class AccountAchievementsController extends Controller
{
    public function update(
        UpdateAccountAchievementsRequest $request,
        AchievementPreferencesRepositoryInterface $preferences,
        AchievementsSettingsInterface $settings,
    ): RedirectResponse {
        $current = $preferences->find((string) $request->user()?->getAuthIdentifier());
        $switches = $settings->settings();

        $preferences->save($current->withChoices(
            announceRare: $switches->announcements ? $request->announceRare($current->announceRare) : $current->announceRare,
            inLeaderboard: $switches->leaderboard ? $request->inLeaderboard($current->inLeaderboard) : $current->inLeaderboard,
        ));

        return back(303);
    }
}
