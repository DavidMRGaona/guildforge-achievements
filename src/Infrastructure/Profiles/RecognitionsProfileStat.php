<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Profiles;

use App\Application\Profiles\Contracts\DescribesProfileStat;
use App\Application\Profiles\Contracts\ProfileStatInterface;
use App\Application\Profiles\DTOs\ProfileOwnerDTO;
use App\Application\Profiles\DTOs\ProfileStatDTO;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Achievements\Application\DTOs\RecognitionTotalDTO;
use Modules\Achievements\Application\Services\AchievementShowcaseInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\RecognitionsInterface;

/**
 * «Reconocimientos» on the stats row (`achievements.recognitions`, #11): how many the owner
 * received, of any type. Now for the owner; up to the start of the month for anybody else. Nothing
 * when the owner hid their achievements, while recognitions are off, or while there are none.
 */
final readonly class RecognitionsProfileStat implements DescribesProfileStat, ProfileStatInterface
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
        return __('achievements::messages.recognitions.stat.label');
    }

    public function description(): string
    {
        return __('achievements::messages.recognitions.stat.description');
    }

    public function order(): int
    {
        return 60;
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return $this->settings->recognitionsEnabled();
    }

    public function stat(ProfileOwnerDTO $owner, ?Authenticatable $viewer): ?ProfileStatDTO
    {
        $viewerId = $viewer instanceof Authenticatable ? (string) $viewer->getAuthIdentifier() : null;

        if (! $this->showcase->isVisibleTo($owner->id, $viewerId)) {
            return null;
        }

        $count = array_sum(array_map(static fn (RecognitionTotalDTO $total): int => $total->count, $this->recognitions->totals($owner->id, $viewerId)));

        return $count === 0 ? null : new ProfileStatDTO($count);
    }
}
