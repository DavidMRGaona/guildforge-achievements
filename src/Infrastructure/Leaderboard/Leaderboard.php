<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Leaderboard;

use App\Application\Profiles\DTOs\PublicProfileDTO;
use App\Application\Profiles\Services\ProfileAccessRule;
use App\Domain\Activity\ActivityIdentifierRule;
use App\Infrastructure\Seo\CloudinaryImageUrlBuilder;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Achievements\Application\DTOs\LeaderboardEntryDTO;
use Modules\Achievements\Application\DTOs\LeaderboardPageDTO;
use Modules\Achievements\Application\Enums\LeaderboardViewerStatus;
use Modules\Achievements\Application\Services\AchievementLeaderboardInterface;
use Modules\Achievements\Application\Services\AchievementPointsInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Repositories\AchievementPointsRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\Repositories\PointsSnapshotRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\LevelScale;
use Modules\Achievements\Domain\ValueObjects\PointsPeriod;
use Modules\Achievements\Infrastructure\Support\AchievementWarnings;
use Throwable;

/**
 * On every request, over last night's photo of points (ids and points only; the viewer's own row
 * as it is now, so nobody sees the points of two people who played together rise at once):
 *
 * 1. the core describes each candidate's profile and its ProfileAccessRule keeps those the viewer
 *    may see (visibleAmong(): deactivated, anonymized, hidden or stricter profiles go, as on the
 *    profile page; the viewer always sees their own);
 * 2. accounts without a public name go, the viewer's own included: nobody is ever shown by the
 *    account name;
 * 3. each person's choices are read again, so hiding achievements or opting out takes effect at once;
 * 4. each total is the one this viewer may see: their own in full, anybody else's without the
 *    achievements that are secret now and masked to the viewer (not held by them, not featured by the owner). A total minus
 *    what the viewer can see would otherwise give a secret's points, and with them who holds it.
 *    Rows left without points go.
 *
 * Ranks are worked out over what is left, ties sharing a rank («1, 2, 2, 4»): a gap or a position
 * never stands for somebody the viewer may not see. Ties are ordered by public name and account id,
 * never by when anything was granted.
 */
final readonly class Leaderboard implements AchievementLeaderboardInterface
{
    private const int AVATAR_SIZE = 64;

    public function __construct(
        private PointsSnapshotRepositoryInterface $snapshots,
        private LeaderboardProfiles $profiles,
        private ProfileAccessRule $access,
        private AchievementPreferencesRepositoryInterface $preferences,
        private AchievementPointsRepositoryInterface $points,
        private AchievementPointsInterface $pointsShown,
        private AchievementsSettingsInterface $settings,
        private CloudinaryImageUrlBuilder $cloudinary,
        private AchievementWarnings $warnings,
    ) {}

    public function page(Authenticatable $viewer, PointsPeriod $period, int $page): LeaderboardPageDTO
    {
        $viewerId = ActivityIdentifierRule::userId((string) $viewer->getAuthIdentifier()) ?? strtolower((string) $viewer->getAuthIdentifier());

        try {
            // No photo yet (the first night after points were switched on): nobody, never a 0
            if (! $this->snapshots->takenAt() instanceof DateTimeImmutable) {
                return LeaderboardPageDTO::unavailable();
            }

            $rows = $this->visibleRows($viewer, $viewerId, $period);
        } catch (Throwable $exception) {
            $this->warnings->warn('leaderboard_unavailable', '[Achievements] The leaderboard could not be worked out; nobody is shown.', [
                'reason' => 'leaderboard_unavailable',
                'exception' => $exception::class,
            ]);

            // Nothing said about the viewer either: a «no points» there could be wrong
            return LeaderboardPageDTO::unavailable();
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

    /**
     * The standings the viewer may see with the totals they may see, the most points first, then by
     * public name.
     *
     * @return list<array{userId: string, points: int, publicName: string, handle: string, avatarPublicId: string|null}>
     */
    private function visibleRows(Authenticatable $viewer, string $viewerId, PointsPeriod $period): array
    {
        // Last night's photo for everybody; the viewer's own points as they are now
        $standings = $this->snapshots->forPeriod($period->key());
        $standings[$viewerId] = $this->points->breakdowns([$viewerId], $period)[$viewerId];

        $described = $this->profiles->describe(array_map(strval(...), array_keys($standings)));
        $visible = array_flip(array_map(
            strtolower(...),
            $this->access->visibleAmong(array_values(array_map(static fn (array $one): PublicProfileDTO => $one['profile'], $described)), $viewer),
        ));

        $named = [];

        foreach ($described as $userId => $one) {
            $name = $one['profile']->shownPublicName();

            if ($name !== null && isset($visible[$userId], $standings[$userId])) {
                $named[$userId] = [
                    'publicName' => $name,
                    'handle' => $one['profile']->handle,
                    'avatarPublicId' => $one['avatarPublicId'],
                ];
            }
        }

        if ($named === []) {
            return [];
        }

        $viewerHolds = $this->pointsShown->heldBy($viewerId);
        $open = $this->pointsShown->openIds();
        $rows = [];

        foreach ($this->preferences->forUsers(array_keys($named)) as $userId => $choices) {
            if (! $choices->appearsInLeaderboard() || ! isset($named[$userId])) {
                continue;
            }

            $points = $userId === $viewerId
                ? $standings[$userId]->total()
                // Featuring reveals a secret to everybody; holding it too reveals it to the viewer
                : $standings[$userId]->visibleWith($open, array_fill_keys($choices->featured, true) + $viewerHolds);

            if ($points > 0) {
                $rows[] = ['userId' => $userId, 'points' => $points] + $named[$userId];
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
     * Why the viewer is not listed, from their own choices, name and points only; null (nothing
     * said) when any of it cannot be read, never a misleading «no points».
     */
    private function statusOf(string $viewerId, PointsPeriod $period): ?LeaderboardViewerStatus
    {
        try {
            $choices = $this->preferences->find($viewerId);

            if ($choices->hidden) {
                return LeaderboardViewerStatus::Hidden;
            }

            if (! $choices->inLeaderboard) {
                return LeaderboardViewerStatus::OptedOut;
            }

            if ($this->points->breakdowns([$viewerId], $period)[$viewerId]->total() === 0) {
                return LeaderboardViewerStatus::NoPoints;
            }

            $profile = $this->profiles->describe([$viewerId])[$viewerId]['profile'] ?? null;

            if ($profile === null || $profile->shownPublicName() === null) {
                return LeaderboardViewerStatus::NoPublicName;
            }
        } catch (Throwable) {
            return null;
        }

        // Listed by every rule: nothing to tell (never reached, the viewer's row is read as it is now)
        return null;
    }
}
