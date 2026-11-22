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
use Modules\Achievements\Infrastructure\Profiles\AchievementShowcase;
use Modules\Achievements\Infrastructure\Profiles\ShowcasePresenter;

/**
 * `/logros`: what the person holds and every achievement that can still be got, with how rare it
 * is (the features follow the modules installed: an achievement without a source is not offered). A
 * secret achievement the person does not hold keeps its name, look, id, points and rarity to itself;
 * nothing names who holds what.
 */
final class AchievementCatalogueController extends Controller
{
    public function __construct(
        private readonly AchievementShowcaseInterface $showcase,
        private readonly AchievementRarityInterface $rarity,
        private readonly AchievementsSettingsInterface $settings,
        private readonly ShowcasePresenter $presenter,
        private readonly SeoMetadataFactoryInterface $seo,
    ) {}

    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();
        $viewerId = $viewer === null ? null : (string) $viewer->getAuthIdentifier();
        $showsRarity = $this->settings->rarityEnabled();
        $rarity = $showsRarity ? $this->rarity->current() : null;
        $showsPoints = $this->settings->pointsEnabled();

        return Inertia::render('Achievements/Index', [
            'achievements' => array_map(fn (AchievementViewDTO $view): array => $this->presenter->achievement($view) + [
                'held' => $view->held,
                // What it gives (#10); a masked secret keeps it to itself
                'points' => $showsPoints && ! $view->masked ? $view->points : null,
                // 0 of what the viewer holds only says they are not active: not shown
                'rarity' => match (true) {
                    $view->masked => null,
                    $view->held => AchievementShowcase::knownShare($rarity?->shareOf($view->id)),
                    default => $rarity?->shareOf($view->id),
                },
            ], $this->showcase->catalogueFor($viewerId)),
            'showsRarity' => $showsRarity && $rarity instanceof AchievementRarityDTO && $rarity->activePeople > 0,
            'signedIn' => $viewer !== null,
            'showsPoints' => $showsPoints,
            // Only signed-in accounts open it
            'leaderboardUrl' => $viewer !== null && $this->settings->leaderboardShown() ? route('achievements.leaderboard', absolute: false) : null,
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: __('achievements::messages.pages.index_title'),
                description: __('achievements::messages.pages.index_description'),
            ))->toArray(),
        ]);
    }
}
