<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Profiles;

use App\Application\Profiles\Contracts\DescribesProfileStat;
use App\Application\Profiles\Contracts\ProfileStatInterface;
use App\Application\Profiles\DTOs\ProfileOwnerDTO;
use App\Application\Profiles\DTOs\ProfileStatDTO;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Achievements\Application\Services\AchievementShowcaseInterface;

/**
 * «Logros» on the stats row (`achievements.count`): how many achievements the owner holds, secret
 * ones included (a total names none of them). Nothing for anybody else when the owner hid their
 * achievements, and nothing while they hold none.
 */
final readonly class AchievementsCountProfileStat implements DescribesProfileStat, ProfileStatInterface
{
    public const string KEY = 'achievements.count';

    public function __construct(
        private AchievementShowcaseInterface $showcase,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('achievements::messages.profile.count.label');
    }

    public function description(): string
    {
        return __('achievements::messages.profile.count.description');
    }

    public function order(): int
    {
        return 50;
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function stat(ProfileOwnerDTO $owner, ?Authenticatable $viewer): ?ProfileStatDTO
    {
        $viewerId = $viewer instanceof Authenticatable ? (string) $viewer->getAuthIdentifier() : null;

        if (! $this->showcase->isVisibleTo($owner->id, $viewerId)) {
            return null;
        }

        $count = $this->showcase->heldCount($owner->id);

        return $count === 0 ? null : new ProfileStatDTO($count, route('achievements.index', absolute: false));
    }
}
