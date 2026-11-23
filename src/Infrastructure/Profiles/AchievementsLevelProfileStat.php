<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Profiles;

use App\Application\Profiles\Contracts\DescribesProfileStat;
use App\Application\Profiles\Contracts\ProfileStatInterface;
use App\Application\Profiles\DTOs\ProfileOwnerDTO;
use App\Application\Profiles\DTOs\ProfileStatDTO;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Achievements\Application\DTOs\PointsSummaryDTO;
use Modules\Achievements\Application\Services\AchievementPointsInterface;
use Modules\Achievements\Application\Services\AchievementShowcaseInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\ValueObjects\Level;

/**
 * «Nivel» on the stats row (`achievements.level`, #10): the level the owner's points reach (its
 * name is in the section): the owner's own at once, last night's for anybody else, so the stat of
 * people who played together never rises at once on their profile, their card or the forum. Nothing while levels or points are off, while the owner holds no points,
 * and for anybody else once the owner hid their achievements.
 */
final readonly class AchievementsLevelProfileStat implements DescribesProfileStat, ProfileStatInterface
{
    public const string KEY = 'achievements.level';

    public function __construct(
        private AchievementShowcaseInterface $showcase,
        private AchievementPointsInterface $points,
        private AchievementsSettingsInterface $settings,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('achievements::messages.profile.level.label');
    }

    public function description(): string
    {
        return __('achievements::messages.profile.level.description');
    }

    public function order(): int
    {
        return 55;
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return $this->settings->levelsShown();
    }

    public function stat(ProfileOwnerDTO $owner, ?Authenticatable $viewer): ?ProfileStatDTO
    {
        if (! $this->settings->levelsShown()) {
            return null;
        }

        $viewerId = $viewer instanceof Authenticatable ? (string) $viewer->getAuthIdentifier() : null;

        if (! $this->showcase->isVisibleTo($owner->id, $viewerId)) {
            return null;
        }

        // The level of the total this viewer may see: last night's photo for anybody but the owner,
        // without the secrets masked to them; nothing while there is no photo
        $summary = $this->points->summary($owner->id, $viewerId);

        if (! $summary instanceof PointsSummaryDTO || $summary->total === 0 || ! $summary->level instanceof Level) {
            return null;
        }

        // Only signed-in accounts open the leaderboard
        $url = $viewerId !== null && $this->settings->leaderboardShown()
            ? route('achievements.leaderboard', absolute: false)
            : route('achievements.index', absolute: false);

        return new ProfileStatDTO($summary->level->number, $url);
    }
}
