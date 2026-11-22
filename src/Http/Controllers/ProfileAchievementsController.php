<?php

declare(strict_types=1);

namespace Modules\Achievements\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\Achievements\Application\Services\AchievementLeaderboardInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Http\Requests\UpdateProfileAchievementsRequest;

/**
 * Saves the owner's choices and goes back to the profile, which draws them again (the section, the
 * count, the level and the badges under the name).
 */
final class ProfileAchievementsController extends Controller
{
    public function update(
        UpdateProfileAchievementsRequest $request,
        AchievementPreferencesRepositoryInterface $preferences,
        AchievementLeaderboardInterface $leaderboard,
    ): RedirectResponse {
        $choices = $preferences->find($request->ownerId())->with($request->hidden(), $request->featuredIds());
        $appears = $request->leaderboard();

        $preferences->save($appears === null ? $choices : $choices->withChoices(announceRare: $choices->announceRare, inLeaderboard: $appears));
        // Choices are read again on every request; this only lets someone who came back appear at once
        $leaderboard->forget();

        return back(303);
    }
}
