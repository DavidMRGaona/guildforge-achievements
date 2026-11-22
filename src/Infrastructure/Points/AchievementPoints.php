<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Points;

use App\Application\Services\TimezoneServiceInterface;
use App\Domain\Activity\ActivityIdentifierRule;
use Modules\Achievements\Application\DTOs\PointsSummaryDTO;
use Modules\Achievements\Application\Services\AchievementPointsInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPointsRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\PointsBreakdown;
use Modules\Achievements\Domain\ValueObjects\PointsPeriod;

/**
 * Seasons are calendar years in the display zone: their bounds are midnight of the 1st of January
 * there, as storage-zone instants.
 *
 * A total is the owner's to see in full. Anybody else sees it without the secret achievements that
 * are masked to them (not held by them, not featured by the owner), as the section and the catalogue
 * mask them: «the secrets of others are only counted».
 */
final class AchievementPoints implements AchievementPointsInterface
{
    /** @var array<string, PointsSummaryDTO> */
    private array $summaries = [];

    /** @var array<string, array<string, true>> */
    private array $held = [];

    public function __construct(
        private readonly AchievementPointsRepositoryInterface $points,
        private readonly AchievementGrantRepositoryInterface $grants,
        private readonly AchievementPreferencesRepositoryInterface $preferences,
        private readonly AchievementsSettingsInterface $settings,
        private readonly TimezoneServiceInterface $time,
    ) {}

    public function currentSeason(): int
    {
        return (int) $this->time->today()->format('Y');
    }

    public function period(?int $season): PointsPeriod
    {
        if ($season === null) {
            return PointsPeriod::allTime();
        }

        return PointsPeriod::season(
            $season,
            $this->time->fromDisplayWallTime(sprintf('%04d-01-01 00:00', $season)),
            $this->time->fromDisplayWallTime(sprintf('%04d-01-01 00:00', $season + 1)),
        );
    }

    public function seasons(): array
    {
        $current = $this->currentSeason();
        $first = $this->points->firstGrantedAt();
        $oldest = $first instanceof \DateTimeImmutable ? min($current, (int) $this->time->toDisplay($first)->format('Y')) : $current;

        return range($current, max($oldest, PointsPeriod::MIN_SEASON));
    }

    public function summary(string $userId, ?string $viewerId): PointsSummaryDTO
    {
        $userId = $this->id($userId);
        $viewerId = $viewerId === null ? null : $this->id($viewerId);
        $key = $userId.'|'.($viewerId ?? '');

        if (isset($this->summaries[$key])) {
            return $this->summaries[$key];
        }

        $revealed = $viewerId === $userId ? null : $this->revealed($userId, $viewerId);
        $total = $this->visible($this->points->breakdowns([$userId], PointsPeriod::allTime())[$userId], $revealed);
        $seasonYear = $this->currentSeason();
        $season = $this->settings->seasonsAndStreaksEnabled()
            ? $this->visible($this->points->breakdowns([$userId], $this->period($seasonYear))[$userId], $revealed)
            : null;
        $scale = $this->settings->levelsShown() ? $this->settings->levelScale() : null;

        return $this->summaries[$key] = new PointsSummaryDTO(
            total: $total,
            season: $season,
            seasonYear: $seasonYear,
            level: $scale?->levelFor($total),
            nextLevel: $scale?->nextAfter($total),
        );
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
     * The secret achievements of an owner revealed to a viewer: those the viewer holds too and those
     * the owner featured (featuring reveals one to everybody).
     *
     * @return array<string, true>
     */
    private function revealed(string $ownerId, ?string $viewerId): array
    {
        $revealed = array_fill_keys($this->preferences->find($ownerId)->featured, true);

        return $viewerId === null ? $revealed : $revealed + $this->heldBy($viewerId);
    }

    /**
     * @param  array<string, true>|null  $revealed  null for the owner: everything
     */
    private function visible(PointsBreakdown $points, ?array $revealed): int
    {
        return $revealed === null ? $points->total() : $points->visibleWith($revealed);
    }

    private function id(string $id): string
    {
        return ActivityIdentifierRule::userId($id) ?? strtolower($id);
    }
}
