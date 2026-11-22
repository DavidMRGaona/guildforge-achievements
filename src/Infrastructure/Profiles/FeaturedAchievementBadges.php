<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Profiles;

use App\Application\Profiles\Contracts\ProfileBadgeSourceInterface;
use App\Application\Profiles\DTOs\ProfileBadgeDTO;
use App\Domain\Profiles\ProfileBadgeColour;
use Modules\Achievements\Application\Services\AchievementShowcaseInterface;

/**
 * The featured achievements as the core's profile badges (core#776): under the name on the
 * profile, on the profile card and next to the author in the forum. The core asks only about
 * profiles the viewer may see, and never says who views: a badge is the owner's choice (or their
 * rarest achievements), the same for everybody.
 */
final readonly class FeaturedAchievementBadges implements ProfileBadgeSourceInterface
{
    public const string KEY_PREFIX = 'achievements.';

    public function __construct(
        private AchievementShowcaseInterface $showcase,
        private AchievementImages $images,
    ) {}

    public function badgesFor(array $userIds): array
    {
        $url = route('achievements.index', absolute: false);
        $badges = [];

        foreach ($this->showcase->featured($userIds) as $userId => $views) {
            foreach ($views as $view) {
                $badges[$userId][] = new ProfileBadgeDTO(
                    key: self::KEY_PREFIX.strtolower($view->id),
                    label: $view->name,
                    description: $view->description,
                    icon: $view->icon,
                    // A colour the core dropped since is drawn in the neutral one rather than lost
                    colour: ProfileBadgeColour::tryFrom($view->colour) ?? ProfileBadgeColour::Slate,
                    imageUrl: $this->images->badge($view->imagePublicId),
                    url: $url,
                );
            }
        }

        return $badges;
    }
}
