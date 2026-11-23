<?php

declare(strict_types=1);

namespace Modules\Achievements\Http\Controllers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Http\Requests\UpdateProfileAchievementsRequest;

/**
 * Saves the owner's choices and goes back to the profile, which draws them again (the section, the
 * count, the level and the badges under the name). What is shown and whether to appear in the
 * leaderboard are saved together or not at all.
 */
final class ProfileAchievementsController extends Controller
{
    public function update(
        UpdateProfileAchievementsRequest $request,
        AchievementPreferencesRepositoryInterface $preferences,
        ConnectionInterface $db,
    ): RedirectResponse {
        $db->transaction(static function () use ($request, $preferences): void {
            $preferences->saveShowcase($request->ownerId(), $request->hidden(), $request->featuredIds());

            // «Aparecer en la clasificación» from the section too (#10); left out, it stays as it was
            $appears = $request->leaderboard();

            if ($appears !== null) {
                $preferences->saveChoices($request->ownerId(), announceRare: null, inLeaderboard: $appears);
            }
        });

        return back(303);
    }
}
