<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Profiles;

use App\Application\Exceptions\NothingToDrawException;
use App\Application\Profiles\Contracts\ProfileSectionInterface;
use App\Application\Profiles\DTOs\ProfileOwnerDTO;
use App\Domain\Profiles\ProfileRegion;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Achievements\Application\DTOs\RecognitionTotalDTO;
use Modules\Achievements\Application\Services\AchievementShowcaseInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\RecognitionsInterface;

/**
 * «Reconocimientos» on the public profile (`achievements.recognitions`, #11): how many of each type
 * the owner received. Totals only, never who gave them or for what game; for anybody but the owner
 * counted up to the start of the month (RecognitionCut), so the number never moves the night after
 * a game. Hidden from everybody else with the owner's achievements.
 */
final readonly class RecognitionsProfileSection implements ProfileSectionInterface
{
    public const string KEY = 'achievements.recognitions';

    public function __construct(
        private AchievementShowcaseInterface $showcase,
        private AchievementsSettingsInterface $settings,
        private RecognitionsInterface $recognitions,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('achievements::messages.recognitions.section.label');
    }

    public function description(): string
    {
        return __('achievements::messages.recognitions.section.description');
    }

    public function component(): string
    {
        return 'components/profileSections/RecognitionsProfileSection.vue';
    }

    public function region(): ProfileRegion
    {
        return ProfileRegion::Aside;
    }

    public function order(): int
    {
        return 30;
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return $this->settings->recognitionsEnabled();
    }

    public function props(ProfileOwnerDTO $owner, ?Authenticatable $viewer): array
    {
        $viewerId = $viewer instanceof Authenticatable ? strtolower((string) $viewer->getAuthIdentifier()) : null;
        $isOwner = $viewerId === strtolower($owner->id);

        if (! $this->showcase->isVisibleTo($owner->id, $viewerId)) {
            throw NothingToDrawException::make();
        }

        $totals = $this->recognitions->totals($owner->id, $viewerId);

        if ($totals === [] && ! $isOwner) {
            throw NothingToDrawException::make();
        }

        return [
            'totals' => array_map(static fn (RecognitionTotalDTO $total): array => $total->toArray(), $totals),
            'isOwner' => $isOwner,
            'pageUrl' => $isOwner ? route('achievements.recognitions', absolute: false) : null,
        ];
    }
}
