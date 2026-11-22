<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Points;

use App\Application\Services\TimezoneServiceInterface;
use App\Domain\Activity\ActivityIdentifierRule;
use Modules\Achievements\Application\DTOs\PointsSummaryDTO;
use Modules\Achievements\Application\Services\AchievementPointsInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Repositories\AchievementPointsRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\PointsPeriod;

/**
 * Seasons are calendar years in the display zone: their bounds are midnight of the 1st of January
 * there, as storage-zone instants.
 */
final class AchievementPoints implements AchievementPointsInterface
{
    /** @var array<string, PointsSummaryDTO> */
    private array $summaries = [];

    public function __construct(
        private readonly AchievementPointsRepositoryInterface $points,
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

    public function summary(string $userId): PointsSummaryDTO
    {
        $userId = ActivityIdentifierRule::userId($userId) ?? strtolower($userId);

        if (isset($this->summaries[$userId])) {
            return $this->summaries[$userId];
        }

        $total = $this->points->totals([$userId], PointsPeriod::allTime())[$userId] ?? 0;
        $seasonYear = $this->currentSeason();
        $season = $this->settings->seasonsAndStreaksEnabled()
            ? ($this->points->totals([$userId], $this->period($seasonYear))[$userId] ?? 0)
            : null;
        $scale = $this->settings->levelsShown() ? $this->settings->levelScale() : null;

        return $this->summaries[$userId] = new PointsSummaryDTO(
            total: $total,
            season: $season,
            seasonYear: $seasonYear,
            level: $scale?->levelFor($total),
            nextLevel: $scale?->nextAfter($total),
        );
    }
}
