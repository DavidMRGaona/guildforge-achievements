<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Leaderboard;

use App\Application\Profiles\Services\ProfileAccessRule;
use App\Domain\Activity\ActivityIdentifierRule;
use App\Infrastructure\Seo\CloudinaryImageUrlBuilder;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Achievements\Application\DTOs\LeaderboardEntryDTO;
use Modules\Achievements\Application\DTOs\LeaderboardPageDTO;
use Modules\Achievements\Application\Enums\LeaderboardViewerStatus;
use Modules\Achievements\Application\Services\AchievementLeaderboardInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Repositories\AchievementPointsRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\LevelScale;
use Modules\Achievements\Domain\ValueObjects\PointsPeriod;
use Modules\Achievements\Infrastructure\Support\AchievementWarnings;
use Throwable;

/**
 * On every request, over the standings (cached points only):
 *
 * 1. the core describes each candidate's profile and its ProfileAccessRule keeps those the viewer
 *    may see (visibleAmong(): deactivated, anonymized, hidden or stricter profiles go, as on the
 *    profile page; the viewer always sees their own);
 * 2. accounts without a public name go, the viewer's own included: nobody is ever shown by the
 *    account name;
 * 3. each person's choices are read again, so hiding achievements or opting out takes effect at once.
 *
 * Ranks are worked out over what is left, ties sharing a rank («1, 2, 2, 4»): a gap or a position
 * never stands for somebody the viewer may not see.
 */
final readonly class Leaderboard implements AchievementLeaderboardInterface
{
    private const int AVATAR_SIZE = 64;

    public function __construct(
        private CachedLeaderboardStandings $standings,
        private LeaderboardProfiles $profiles,
        private ProfileAccessRule $access,
        private AchievementPreferencesRepositoryInterface $preferences,
        private AchievementPointsRepositoryInterface $points,
        private AchievementsSettingsInterface $settings,
        private CloudinaryImageUrlBuilder $cloudinary,
        private AchievementWarnings $warnings,
    ) {}

    public function page(Authenticatable $viewer, PointsPeriod $period, int $page): LeaderboardPageDTO
    {
        $viewerId = ActivityIdentifierRule::userId((string) $viewer->getAuthIdentifier()) ?? strtolower((string) $viewer->getAuthIdentifier());

        try {
            $rows = $this->visibleRows($viewer, $period);
        } catch (Throwable $exception) {
            $this->warnings->warn('leaderboard_unavailable', '[Achievements] The leaderboard could not be worked out; nobody is shown.', [
                'reason' => 'leaderboard_unavailable',
                'exception' => $exception::class,
            ]);

            return LeaderboardPageDTO::unavailable($this->statusOf($viewerId, $period));
        }

        $scale = $this->settings->levelsShown() ? $this->settings->levelScale() : null;
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);
        $entries = [];
        $viewerEntry = null;
        $rank = 0;
        $previous = null;

        foreach ($rows as $position => $row) {
            if ($row['points'] !== $previous) {
                $rank = $position + 1;
                $previous = $row['points'];
            }

            $onPage = intdiv($position, self::PER_PAGE) + 1 === $page;
            $isViewer = $row['userId'] === $viewerId;

            if (! $onPage && ! $isViewer) {
                continue;
            }

            $entry = $this->entry($row, $rank, $isViewer, $scale);

            if ($onPage) {
                $entries[] = $entry;
            }

            if ($isViewer) {
                $viewerEntry = $entry;
            }
        }

        return new LeaderboardPageDTO(
            entries: $entries,
            total: $total,
            page: $page,
            lastPage: $lastPage,
            viewer: $viewerEntry,
            viewerStatus: $viewerEntry instanceof LeaderboardEntryDTO ? LeaderboardViewerStatus::Listed : $this->statusOf($viewerId, $period),
        );
    }

    public function forget(): void
    {
        $this->standings->forget();
    }

    /**
     * The standings the viewer may see, the most points first, then by public name.
     *
     * @return list<array{userId: string, points: int, publicName: string, handle: string, avatarPublicId: string|null}>
     */
    private function visibleRows(Authenticatable $viewer, PointsPeriod $period): array
    {
        $standings = $this->standings->standings($period);

        if ($standings === []) {
            return [];
        }

        $described = $this->profiles->describe(array_column($standings, 'userId'));
        $visible = array_flip(array_map(
            strtolower(...),
            $this->access->visibleAmong(array_column($described, 'profile'), $viewer),
        ));

        $named = [];

        foreach ($standings as $row) {
            $profile = $described[$row['userId']]['profile'] ?? null;
            $name = $profile?->shownPublicName();

            if ($profile !== null && $name !== null && isset($visible[$row['userId']])) {
                $named[$row['userId']] = $row + [
                    'publicName' => $name,
                    'handle' => $profile->handle,
                    'avatarPublicId' => $described[$row['userId']]['avatarPublicId'],
                ];
            }
        }

        if ($named === []) {
            return [];
        }

        $rows = [];

        foreach ($this->preferences->forUsers(array_keys($named)) as $userId => $choices) {
            if ($choices->appearsInLeaderboard() && isset($named[$userId])) {
                $rows[] = $named[$userId];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [-$a['points'], mb_strtolower($a['publicName']), $a['userId']]
            <=> [-$b['points'], mb_strtolower($b['publicName']), $b['userId']]);

        return $rows;
    }

    /**
     * @param  array{userId: string, points: int, publicName: string, handle: string, avatarPublicId: string|null}  $row
     */
    private function entry(array $row, int $rank, bool $isViewer, ?LevelScale $scale): LeaderboardEntryDTO
    {
        $avatar = $row['avatarPublicId'] === null || $row['avatarPublicId'] === ''
            ? null
            : $this->cloudinary->avatar($row['avatarPublicId'], self::AVATAR_SIZE);

        return new LeaderboardEntryDTO(
            rank: $rank,
            points: $row['points'],
            publicName: $row['publicName'],
            profileUrl: route('profiles.show', ['handle' => $row['handle']], absolute: false),
            avatarUrl: $avatar !== null && str_starts_with($avatar, 'https://') ? $avatar : null,
            level: $scale?->levelFor($row['points']),
            isViewer: $isViewer,
        );
    }

    /**
     * Why the viewer is not listed, from their own choices, name and points only.
     */
    private function statusOf(string $viewerId, PointsPeriod $period): LeaderboardViewerStatus
    {
        try {
            $choices = $this->preferences->find($viewerId);

            if ($choices->hidden) {
                return LeaderboardViewerStatus::Hidden;
            }

            if (! $choices->leaderboard) {
                return LeaderboardViewerStatus::OptedOut;
            }

            if (($this->points->totals([$viewerId], $period)[$viewerId] ?? 0) === 0) {
                return LeaderboardViewerStatus::NoPoints;
            }

            $profile = $this->profiles->describe([$viewerId])[$viewerId]['profile'] ?? null;

            if ($profile === null || $profile->shownPublicName() === null) {
                return LeaderboardViewerStatus::NoPublicName;
            }
        } catch (Throwable) {
            return LeaderboardViewerStatus::NoPoints;
        }

        // Points not counted yet in the kept standings (a grant moments ago): they show shortly
        return LeaderboardViewerStatus::Pending;
    }
}
