<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Profiles;

use App\Application\Services\TimezoneServiceInterface;
use Modules\Achievements\Application\DTOs\AchievementViewDTO;
use Modules\Achievements\Application\DTOs\ShowcaseItemDTO;

/**
 * Achievements as the browser gets them, picked field by field: never a rule, the points or anything
 * the panel alone shows.
 */
final readonly class ShowcasePresenter
{
    public function __construct(
        private AchievementImages $images,
        private TimezoneServiceInterface $time,
    ) {}

    /**
     * A masked secret carries no id: one revealed elsewhere (a featured badge) would tell who else
     * holds the same secret. Masked items cannot be featured, so nothing needs it.
     *
     * @return array{id: string|null, name: string, description: string, icon: string, colour: string, imageUrl: string|null, masked: bool}
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function locked(ShowcaseItemDTO $item): array
    {
        return $this->achievement($item->achievement) + [
            'progress' => $item->progress === null || $item->threshold === null ? null : ['value' => $item->progress, 'threshold' => $item->threshold],
            'manual' => $item->manual,
        ];
    }
}
