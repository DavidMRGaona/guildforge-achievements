<?php

declare(strict_types=1);

namespace Modules\Achievements\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Achievements\Application\DTOs\AchievementRarityDTO;
use Modules\Achievements\Application\DTOs\AchievementViewDTO;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Application\Services\AchievementShowcaseInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\ChallengeBoardInterface;
use Modules\Achievements\Application\Services\SeasonClock;
use Modules\Achievements\Infrastructure\Challenges\ChallengePresenter;
use Modules\Achievements\Infrastructure\Profiles\ShowcasePresenter;

/**
 * `/logros`: what the person holds and every achievement that can still be got, with how rare it
 * is (the features follow the modules installed: an achievement without a source is not offered). A
 * secret achievement the person does not hold keeps its name, look, id, points and rarity to itself;
 * nothing names who holds what. The collective challenges come first, with their totals (#12).
 */
final class AchievementCatalogueController extends Controller
{
    /**
     * Resolved per call, never in the constructor: the route keeps its controller, and the showcase,
     * the rarity, the challenges and the settings are bound "scoped" (read once per request).
     */
    public function __invoke(
        Request $request,
        AchievementShowcaseInterface $showcase,
        AchievementRarityInterface $rarity,
        AchievementsSettingsInterface $settings,
        ShowcasePresenter $presenter,
        SeoMetadataFactoryInterface $seo,
        ChallengeBoardInterface $challenges,
        ChallengePresenter $challengePresenter,
        SeasonClock $seasons,
    ): Response {
        $viewer = $request->user();
        $viewerId = $viewer === null ? null : (string) $viewer->getAuthIdentifier();
        $showsRarity = $settings->rarityEnabled();
        $shares = $showsRarity ? $rarity->current() : null;
        $showsPoints = $settings->pointsEnabled();
        // The viewer's own seasons only: nobody else's standing is ever built
        $standings = $showcase->seasonStandings($viewerId);

        return Inertia::render('Achievements/Index', [
            'achievements' => array_map(fn (AchievementViewDTO $view): array => $presenter->achievement($view) + [
                'held' => $view->held,
                // What it gives (#10); a masked secret keeps it to itself
                'points' => $showsPoints && ! $view->masked ? $view->points : null,
                'standing' => $view->masked || ! isset($standings[strtolower($view->id)]) ? null : $presenter->standing($standings[strtolower($view->id)]),
                // A whole percent (0 for «< 1 %»); 0 of what the viewer holds only says they are not
                // active, so it is not shown; nobody active holding something else is said apart
                'rarity' => $view->masked ? null : ShowcasePresenter::shownRarity($shares?->shareOf($view->id)),
                'heldByNobody' => ! $view->masked && ! $view->held && $shares?->shareOf($view->id) === 0.0,
            ], $showcase->catalogueFor($viewerId)),
            'showsRarity' => $showsRarity && $shares instanceof AchievementRarityDTO && $shares->activePeople > 0,
            'signedIn' => $viewer !== null,
            'showsPoints' => $showsPoints,
            // Only signed-in accounts open it
            'leaderboardUrl' => $viewer !== null && $settings->leaderboardShown() ? route('achievements.leaderboard', absolute: false) : null,
            'recognitionsUrl' => $viewer !== null && $settings->recognitionsEnabled() ? route('achievements.recognitions', absolute: false) : null,
            // Collective challenges (#12): totals only, and who completed them as the viewer may see them
            'challenges' => array_map(
                $challengePresenter->challenge(...),
                $challenges->shown($viewer),
            ),
            'season' => $settings->seasonsAndStreaksEnabled() ? $seasons->current()->key() : null,
            'seo' => $seo->forPage(new SeoPageDTO(
                title: __('achievements::messages.pages.index_title'),
                description: __('achievements::messages.pages.index_description'),
            ))->toArray(),
        ]);
    }
}
