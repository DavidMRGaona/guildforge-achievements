<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Profiles;

use App\Application\Services\TimezoneServiceInterface;
use Modules\Achievements\Application\DTOs\AchievementViewDTO;
use Modules\Achievements\Application\DTOs\SeasonStandingDTO;
use Modules\Achievements\Application\DTOs\ShowcaseItemDTO;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;

/**
 * Achievements as the browser gets them, picked field by field: never a rule, the points or anything
 * the panel alone shows.
 */
final readonly class ShowcasePresenter
{
    public function __construct(
        private AchievementImages $images,
        private TimezoneServiceInterface $time,
        private AchievementsSettingsInterface $settings,
    ) {}

    /**
     * A masked secret carries no id: one revealed elsewhere (a featured badge) would tell who else
     * holds the same secret. Masked items cannot be featured, so nothing needs it. `repeats` says it
     * can be got again each season (never for a masked one, nor while seasons and streaks are off).
     *
     * @return array{id: string|null, name: string, description: string, icon: string, colour: string, imageUrl: string|null, masked: bool, repeats: bool}
     */
    public function achievement(AchievementViewDTO $view): array
    {
        return [
            'id' => $view->masked ? null : $view->id,
            'name' => $view->name,
            'description' => $view->description,
            'icon' => $view->icon,
            'colour' => $view->colour,
            'imageUrl' => $view->masked ? null : $this->images->tile($view->imagePublicId),
            'masked' => $view->masked,
            'repeats' => ! $view->masked && $view->rule?->isSeasonal() === true && $this->settings->seasonsAndStreaksEnabled(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function earned(ShowcaseItemDTO $item): array
    {
        return $this->achievement($item->achievement) + [
            // The instant for the owner only; anybody else gets the month (ShowcaseItemDTO)
            'grantedAt' => $this->time->iso($item->grantedAt),
            'grantedMonth' => $item->grantedMonth,
            'rarity' => $item->rarity,
            'featured' => $item->featured,
            'seasons' => $item->seasons,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function locked(ShowcaseItemDTO $item): array
    {
        return $this->achievement($item->achievement) + [
            'progress' => $item->progress === null || $item->threshold === null ? null : ['value' => $item->progress, 'threshold' => $item->threshold, 'inMonths' => $item->inMonths],
            'manual' => $item->manual,
            'season' => $item->season,
        ];
    }

    /**
     * Where the viewer stands in an achievement that repeats per season, for `/logros`.
     *
     * @return array{season: string, heldNow: bool, pastSeasons: list<string>, progress: array{value: int, threshold: int, inMonths: bool}|null}
     */
    public function standing(SeasonStandingDTO $standing): array
    {
        return [
            'season' => $standing->season,
            'heldNow' => $standing->heldNow,
            'pastSeasons' => $standing->pastSeasons,
            'progress' => $standing->progress === null || $standing->target === null
                ? null
                : ['value' => $standing->progress, 'threshold' => $standing->target, 'inMonths' => $standing->inMonths],
        ];
    }
}
