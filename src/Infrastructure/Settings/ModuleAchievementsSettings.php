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
use Modules\Achievements\Domain\ValueObjects\LevelScale;
use Modules\Achievements\Infrastructure\Support\AchievementWarnings;
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

    /** The strict read failed in this request or job: not tried again until save() */
    private bool $unreadable = false;

    public function __construct(
        private readonly ModuleSettingsStoreInterface $store,
        private readonly ModuleManagerServiceInterface $modules,
        private readonly SettingsServiceInterface $settings,
        private readonly AchievementWarnings $warnings,
    ) {}

    public function settings(): AchievementsSettings
    {
        return $this->read ??= AchievementsSettings::fromArray($this->store->stored(self::MODULE));
    }

    /**
     * The core's store answers [] both for «never saved» and «could not read», so the row is read
     * here through getStored(), which throws when the table cannot be reached. The key is the core
     * store's own (`SettingsTableModuleSettingsStore::KEY_PREFIX`), an infrastructure detail of the
     * core read here on purpose until the core offers a read that tells the two apart.
     *
     * A failure is remembered for the rest of the request or job (bound "scoped"), so a page asking
     * per row does not hit a broken table again and again; a queued retry builds a new instance.
     * Keys stored with the wrong type are warned once a day and read as AchievementsSettings says.
     */
    public function readStrictly(): ?AchievementsSettings
    {
        if ($this->strict instanceof AchievementsSettings) {
            return $this->strict;
        }

        if ($this->unreadable) {
            return null;
        }

        $read = $this->readStored();
        $this->unreadable = ! $read instanceof AchievementsSettings;

        if ($read instanceof AchievementsSettings && $read->invalidKeys !== []) {
            $this->warnings->warn('settings_malformed:'.implode(',', $read->invalidKeys), '[Achievements] Some module settings are stored with the wrong type; they are read with their safe default.', [
                'keys' => $read->invalidKeys,
                'reason' => 'settings_malformed',
            ]);
        }

        return $this->strict = $read;
    }

    private function readStored(): ?AchievementsSettings
    {
        try {
            $value = $this->settings->getStored(SettingsTableModuleSettingsStore::KEY_PREFIX.self::MODULE);
        } catch (Throwable) {
            return null;
        }

        if ($value === null || $value === '') {
            return new AchievementsSettings;
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
        return AchievementsSettings::fromArray($decoded);
    }

    public function save(AchievementsSettings $settings): void
    {
        $this->read = null;
        $this->strict = null;
        $this->unreadable = false;

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

    public function levelScale(): LevelScale
    {
        return $this->settings()->levelScale();
    }

    public function levelsShown(): bool
    {
        return $this->settings()->levelsShown();
    }

    public function leaderboardShown(): bool
    {
        return $this->settings()->leaderboardShown();
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
