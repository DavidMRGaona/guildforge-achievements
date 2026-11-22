<?php

declare(strict_types=1);

namespace Modules\Achievements\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Http\Requests\UpdateProfileAchievementsRequest;

/**
 * Saves the owner's choices and goes back to the profile, which draws them again (the section, the
 * count and the badges under the name).
 */
final class ProfileAchievementsController extends Controller
{
    public function update(UpdateProfileAchievementsRequest $request, AchievementPreferencesRepositoryInterface $preferences): RedirectResponse
    {
        $preferences->save($preferences->find($request->ownerId())->with($request->hidden(), $request->featuredIds()));

        return back(303);
    }
}
