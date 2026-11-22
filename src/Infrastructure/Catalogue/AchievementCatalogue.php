<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Catalogue;

use App\Domain\Activity\ActivityIdentifierRule;
use App\Domain\Profiles\ProfileBadgeColour;
use Modules\Achievements\Application\DTOs\AchievementViewDTO;
use Modules\Achievements\Application\Services\AchievementCatalogueInterface;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;

final readonly class AchievementCatalogue implements AchievementCatalogueInterface
{
    /** How a secret achievement looks to whoever does not hold it */
    public const string MASKED_ICON = 'sparkles';

    public function __construct(
        private AchievementRepositoryInterface $achievements,
        private AchievementGrantRepositoryInterface $grants,
    ) {}

    public function forViewer(?string $viewerId): array
    {
        $viewerId = $viewerId === null ? null : ActivityIdentifierRule::userId($viewerId);
        $held = $viewerId === null ? [] : array_fill_keys($this->grants->heldAchievementIds([$viewerId])[$viewerId] ?? [], true);
        $views = [];

        foreach ($this->achievements->all() as $achievement) {
            $holds = isset($held[strtolower($achievement->id)]);

            if ($achievement->active || $holds) {
                $views[] = $this->present($achievement, $holds);
            }
        }

        return $views;
    }

    public function present(Achievement $achievement, bool $held): AchievementViewDTO
    {
        if ($achievement->secret && ! $held) {
            return new AchievementViewDTO(
                id: $achievement->id,
                name: (string) __('achievements::messages.secret.name'),
                description: (string) __('achievements::messages.secret.description'),
                icon: self::MASKED_ICON,
                colour: ProfileBadgeColour::Slate->value,
                imagePublicId: null,
                points: $achievement->points,
                secret: true,
                held: false,
                masked: true,
                rule: null,
            );
        }

        return new AchievementViewDTO(
            id: $achievement->id,
            name: $achievement->name,
            description: $achievement->description,
            icon: $achievement->icon,
            colour: $achievement->colour,
            imagePublicId: $achievement->imagePublicId,
            points: $achievement->points,
            secret: $achievement->secret,
            held: $held,
            masked: false,
            rule: $achievement->rule,
        );
    }
}
