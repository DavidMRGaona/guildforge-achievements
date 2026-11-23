<?php

declare(strict_types=1);

namespace Modules\Achievements\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Http\Requests\UpdateAccountAchievementsRequest;

/**
 * Saves the signed-in account's choices from the «Logros» tab and goes back to it. Only what is sent
 * is written, column by column: announcing is refused while announcements are off (the request);
 * the leaderboard choice is always taken, even before there is a leaderboard. What the profile
 * section chose (hidden, featured) is never touched.
 */
final class AccountAchievementsController extends Controller
{
    public function update(
        UpdateAccountAchievementsRequest $request,
        AchievementPreferencesRepositoryInterface $preferences,
    ): RedirectResponse {
        $preferences->saveChoices(
            (string) $request->user()?->getAuthIdentifier(),
            // The request refuses it while announcements are off
            announceRare: $request->announceRare(),
            inLeaderboard: $request->inLeaderboard(),
        );

        return back(303);
    }
}
