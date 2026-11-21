<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Modules\Achievements\Application\DTOs\AchievementsSettings;

/**
 * The module's switches, as the settings page saved them (with their defaults where nothing is
 * saved). Every mechanic asks here before it shows, answers a route or evaluates a rule.
 *
 * Bound "scoped": the stored row is read once per request or queued job, and save() drops that
 * copy.
 */
interface AchievementsSettingsInterface
{
    public function settings(): AchievementsSettings;

    public function save(AchievementsSettings $settings): void;

    public function progressEnabled(): bool;

    public function rarityEnabled(): bool;

    public function secretsEnabled(): bool;

    public function seasonsAndStreaksEnabled(): bool;

    public function communityEnabled(): bool;

    public function bulkGrantsEnabled(): bool;

    public function announcementsEnabled(): bool;

    /**
     * Share of active people (in percent, 0.1 to 100) at or below which an achievement counts
     * as rare enough to be announced.
     */
    public function announcementsRarityThreshold(): float;

    public function pointsEnabled(): bool;

    public function levelsEnabled(): bool;

    public function leaderboardEnabled(): bool;

    public function recognitionsEnabled(): bool;

    /**
     * Days after a session, campaign or tournament in which its participants may recognise each other.
     */
    public function recognitionsWindowDays(): int;

    public function challengesEnabled(): bool;

    /**
     * Whether a kind of activity (a MetricKinds value, i.e. an `ActivityKind` case) counts.
     * A kind with no stored switch is on.
     */
    public function metricKindEnabled(string $kind): bool;

    /**
     * @return array<string, bool>
     */
    public function metricKinds(): array;
}
