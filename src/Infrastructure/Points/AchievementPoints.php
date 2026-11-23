<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Points;

use App\Application\Services\TimezoneServiceInterface;
use App\Domain\Activity\ActivityIdentifierRule;
use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Achievements\Application\DTOs\PointsSummaryDTO;
use Modules\Achievements\Application\Services\AchievementPointsInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\PointsCutoffInterface;
use Modules\Achievements\Application\Services\SeasonClock;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPointsRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\PointsPeriod;
use Modules\Achievements\Domain\ValueObjects\Season;

/**
 * Seasons are #8's (Season, SeasonClock): calendar years in the display zone. Their bounds are
 * midnight of the 1st of January there, as storage-zone instants.
 *
 * The owner sees their own points at once and in full. Anybody else sees them from the grants made
 * before the cut (PointsCutoffInterface), read live: what is new waits for the night, so points of
 * people who played together never rise at once in front of them, while a revoke, a change of points
 * or an achievement made secret applies at once. And without the achievements that are secret now
 * and masked to them (not held by them, not featured by the owner), as the section and the catalogue
 * mask them: «the secrets of others are only counted». Without a cut they see nothing, never a 0.
 */
final class AchievementPoints implements AchievementPointsInterface
{
    /** @var array<string, PointsSummaryDTO|null> */
    private array $summaries = [];

    /** @var array<string, array<string, true>> */
    private array $held = [];

    /** @var array<string, true>|null */
    private ?array $open = null;

    public function __construct(
        private readonly AchievementPointsRepositoryInterface $points,
        private readonly AchievementGrantRepositoryInterface $grants,
        private readonly AchievementPreferencesRepositoryInterface $preferences,
        private readonly PointsCutoffInterface $cutoff,
        private readonly AchievementsSettingsInterface $settings,
        private readonly TimezoneServiceInterface $time,
        private readonly SeasonClock $clock,
    ) {}

    public function currentSeason(): int
    {
        return $this->clock->current()->year;
    }

    public function period(?int $season): PointsPeriod
    {
        if ($season === null) {
            return PointsPeriod::allTime();
        }

        $chosen = Season::ofYear($season) ?? throw new InvalidArgumentException('Not a season.');

        return PointsPeriod::season(
            $chosen,
            $this->time->fromDisplayWallTime($chosen->key().'-01-01 00:00'),
            $this->time->fromDisplayWallTime(sprintf('%04d-01-01 00:00', $chosen->year + 1)),
        );
    }

    public function seasons(?DateTimeImmutable $before = null): array
    {
        $current = $this->clock->current()->year;
        $oldest = $current;
        $first = $this->points->firstGrantedAt($before);

        if ($first instanceof DateTimeImmutable) {
            $oldest = min($oldest, Season::containing($first, $this->time->displayTimezone())->year);
        }

        // A grant for the season before, made in January, counts there
        $earliestKey = Season::fromKey($this->points->earliestSeasonKey($before));

        if ($earliestKey instanceof Season) {
            $oldest = min($oldest, $earliestKey->year);
        }

        return range($current, $oldest);
    }

    public function summary(string $userId, ?string $viewerId): ?PointsSummaryDTO
    {
        $userId = $this->id($userId);
        $viewerId = $viewerId === null ? null : $this->id($viewerId);
        $key = $userId.'|'.($viewerId ?? '');

        if (array_key_exists($key, $this->summaries)) {
            return $this->summaries[$key];
        }

        $seasonYear = $this->currentSeason();
        $seasonPeriod = $this->settings->seasonsAndStreaksEnabled() ? $this->period($seasonYear) : null;

        if ($viewerId === $userId) {
            // The owner's own points, at once and in full
            $total = $this->points->breakdowns([$userId], PointsPeriod::allTime())[$userId]->total();
            $season = $seasonPeriod instanceof PointsPeriod ? $this->points->breakdowns([$userId], $seasonPeriod)[$userId]->total() : null;
        } else {
            // Anybody else: the grants still held that were made before the cut, without the secrets
            // masked to them; nothing without a cut
            $cut = $this->cutoff->takenAt();

            if (! $cut instanceof DateTimeImmutable) {
                return $this->summaries[$key] = null;
            }

            $revealed = $this->revealedSecrets($userId, $viewerId);
            $total = $this->beforeCut($userId, PointsPeriod::allTime(), $cut, $revealed);
            $season = $seasonPeriod instanceof PointsPeriod ? $this->beforeCut($userId, $seasonPeriod, $cut, $revealed) : null;
        }

        $scale = $this->settings->levelsShown() ? $this->settings->levelScale() : null;

        return $this->summaries[$key] = new PointsSummaryDTO(
            total: $total,
            season: $season,
            seasonYear: $seasonYear,
            level: $scale?->levelFor($total),
            nextLevel: $scale?->nextAfter($total),
        );
    }

    public function openIds(): array
    {
        return $this->open ??= $this->points->openIds();
    }

    public function revealedSecrets(string $ownerId, ?string $viewerId): array
    {
        $revealed = array_fill_keys($this->preferences->find($this->id($ownerId))->featured, true);

        return $viewerId === null ? $revealed : $revealed + $this->heldBy($viewerId);
    }

    public function heldBy(string $viewerId): array
    {
        $viewerId = $this->id($viewerId);

        if (! isset($this->held[$viewerId])) {
            $this->held[$viewerId] = [];

            foreach ($this->grants->forUser($viewerId) as $grant) {
                $this->held[$viewerId][strtolower($grant->achievementId)] = true;
            }
        }

        return $this->held[$viewerId];
    }

    /**
     * @param  array<string, true>  $revealed
     */
    private function beforeCut(string $userId, PointsPeriod $period, DateTimeImmutable $cut, array $revealed): int
    {
        // Only achievements the owner held before the cut are in the breakdown, so a featured
        // secret revoked or got today never adds up
        return $this->points->breakdowns([$userId], $period, $cut)[$userId]->visibleWith($this->openIds(), $revealed);
    }

    private function id(string $id): string
    {
        return ActivityIdentifierRule::userId($id) ?? strtolower($id);
    }
}
