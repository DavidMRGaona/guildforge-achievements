<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Settings;

use App\Application\Modules\Services\ModuleManagerServiceInterface;
use App\Application\Modules\Services\ModuleSettingsStoreInterface;
use App\Application\Services\SettingsServiceInterface;
use App\Domain\Modules\ValueObjects\ModuleName;
use App\Infrastructure\Modules\Services\SettingsTableModuleSettingsStore;
use JsonException;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Throwable;

/**
 * The settings live in the module's own row of the settings table ("module_settings:achievements"),
 * the one every module uses.
 *
 * Reads go to the stored row, not to the copy the core puts in the configuration at boot: a queue
 * worker that started before a change still sees the new values. Bound "scoped", so the row is
 * read once per request or queued job; save() drops that copy.
 */
final class ModuleAchievementsSettings implements AchievementsSettingsInterface
{
    private const string MODULE = 'achievements';

    private ?AchievementsSettings $read = null;

    private ?AchievementsSettings $strict = null;

    public function __construct(
        private readonly ModuleSettingsStoreInterface $store,
        private readonly ModuleManagerServiceInterface $modules,
        private readonly SettingsServiceInterface $settings,
    ) {}

    public function settings(): AchievementsSettings
    {
        return $this->read ??= AchievementsSettings::fromArray($this->store->stored(self::MODULE));
    }

    /**
     * The core's store answers [] both for «never saved» and «could not read», so the row is read
     * here through getStored(), which throws when the table cannot be reached. A failure is not
     * remembered: the next call tries again.
     */
    public function readStrictly(): ?AchievementsSettings
    {
        if ($this->strict instanceof AchievementsSettings) {
            return $this->strict;
        }

        try {
            $value = $this->settings->getStored(SettingsTableModuleSettingsStore::KEY_PREFIX.self::MODULE);
        } catch (Throwable) {
            return null;
        }

        if ($value === null || $value === '') {
            return $this->strict = new AchievementsSettings;
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $this->strict = AchievementsSettings::fromArray($decoded);
    }

    public function save(AchievementsSettings $settings): void
    {
        $this->read = null;
        $this->strict = null;

        // Through the module manager, so the configuration of this request sees the new values too
        $this->modules->updateSettings(new ModuleName(self::MODULE), $settings->toArray());
    }

    public function progressEnabled(): bool
    {
        return $this->settings()->progress;
    }

    public function rarityEnabled(): bool
    {
        return $this->settings()->rarity;
    }

    public function secretsEnabled(): bool
    {
        return $this->settings()->secrets;
    }

    public function seasonsAndStreaksEnabled(): bool
    {
        return $this->settings()->seasonsAndStreaks;
    }

    public function communityEnabled(): bool
    {
        return $this->settings()->community;
    }

    public function bulkGrantsEnabled(): bool
    {
        return $this->settings()->bulkGrants;
    }

    public function announcementsEnabled(): bool
    {
        return $this->settings()->announcements;
    }

    public function announcementsRarityThreshold(): float
    {
        return $this->settings()->announcementsRarityThreshold;
    }

    public function pointsEnabled(): bool
    {
        return $this->settings()->points;
    }

    public function levelsEnabled(): bool
    {
        return $this->settings()->levels;
    }

    public function leaderboardEnabled(): bool
    {
        return $this->settings()->leaderboard;
    }

    public function recognitionsEnabled(): bool
    {
        return $this->settings()->recognitions;
    }

    public function recognitionsWindowDays(): int
    {
        return $this->settings()->recognitionsWindowDays;
    }

    public function challengesEnabled(): bool
    {
        return $this->settings()->challenges;
    }

    public function metricKindEnabled(string $kind): bool
    {
        return $this->settings()->metricKindEnabled($kind);
    }

    public function metricKinds(): array
    {
        return $this->settings()->allMetricKinds();
    }
}
