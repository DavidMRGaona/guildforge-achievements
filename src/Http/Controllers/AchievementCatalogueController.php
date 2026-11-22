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
use Modules\Achievements\Application\Services\AchievementCatalogueInterface;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Infrastructure\Profiles\ShowcasePresenter;

/**
 * `/logros`: every achievement that can be shown, with how rare it is. A secret achievement the
 * person does not hold keeps its name, look and rarity to itself; nothing names who holds what.
 */
final class AchievementCatalogueController extends Controller
{
    public function __construct(
        private readonly AchievementCatalogueInterface $catalogue,
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

        return Inertia::render('Achievements/Index', [
            'achievements' => array_map(fn (AchievementViewDTO $view): array => $this->presenter->achievement($view) + [
                'held' => $view->held,
                'rarity' => $view->masked ? null : $rarity?->shareOf($view->id),
            ], $this->catalogue->forViewer($viewerId)),
            'showsRarity' => $showsRarity && $rarity instanceof AchievementRarityDTO && $rarity->activePeople > 0,
            'signedIn' => $viewer !== null,
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: __('achievements::messages.pages.index_title'),
                description: __('achievements::messages.pages.index_description'),
            ))->toArray(),
        ]);
    }
}
