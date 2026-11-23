<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use App\Application\Services\TimezoneServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Achievements\Application\DTOs\AchievementRarityDTO;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Exceptions\AchievementSettingsUnreadableException;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\Repositories\PendingAchievementNoticeRepositoryInterface;
use Modules\Achievements\Domain\Rules\AnnouncementRule;
use Modules\Achievements\Notifications\AchievementsAnnouncedNotification;

/**
 * The monthly summary of announcements (achievements#6): on the first day of each month
 * (RELEASE_DAY at RELEASE_AT, display zone), the rare and challenge achievements got before that
 * month, told to whoever may see each owner's profile in one notice per reader and month: each
 * grant goes under the display month it was got in, so a late one never names a false month.
 *
 * Others never learn more of when an achievement was got than its month: grants of one evaluation
 * share their second, so telling them at once, or even on the same day, would say who played with
 * whom. Inside the summary each person is listed by name, never by when or together with whom.
 *
 * It decides then, and fails closed: settings read strictly (unreadable: nothing is taken, the job
 * is tried again and fails loudly after its last try), the mechanic on (off: what waited is dropped,
 * never told later), the owner active, allowing it and showing their achievements, and each grant a
 * challenge one or rare now (AnnouncementRule). The delivery asks again for each reader. What waited
 * is forgotten only once every batch is queued (after the commit), in the same transaction.
 */
final class ReleaseAchievementAnnouncementsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int RELEASE_DAY = 1;

    /** When the month's summary goes out, in the display zone */
    public const string RELEASE_AT = '10:00';

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [600, 3600, 21600];

    public int $uniqueFor = 3600;

    public function handle(
        PendingAchievementNoticeRepositoryInterface $pending,
        AchievementGrantRepositoryInterface $grants,
        AchievementPreferencesRepositoryInterface $preferences,
        AchievementsSettingsInterface $settingsService,
        AchievementRarityInterface $rarityService,
        TimezoneServiceInterface $timezone,
    ): void {
        $settings = $settingsService->readStrictly();

        if (! $settings instanceof AchievementsSettings) {
            $this->waitForReadableSettings();

            return;
        }

        // Everything got before this display month: what arrives late (a grant of the 31st queued
        // after this release, a month whose release failed) goes in the next one, under its own month
        $before = $timezone->displayMonthRange($timezone->today())[0];
        // And before the nightly photo, as everything others see: without one nothing is told yet,
        // challenges included, and what waits stays for the next release
        $takenAt = $rarityService->current()->takenAt;

        if (! $takenAt instanceof DateTimeImmutable) {
            return;
        }

        $before = min($before, $takenAt);

        $pending->releaseAnnouncements($before, function (array $waiting) use ($settings, $grants, $preferences, $rarityService, $timezone): void {
            // Off: what waited is dropped, never told once the mechanic comes back
            if (! $settings->announcements) {
                return;
            }

            $rarity = $rarityService->current();
            $byMonth = [];

            foreach ($waiting as $ownerId => $grantIds) {
                foreach ($this->announced((string) $ownerId, $grantIds, $grants, $preferences, $settings, $rarity) as $grant) {
                    $month = $timezone->toDisplay($grant->grantedAt)->format('Y-m');
                    $achievementId = strtolower($grant->achievementId);
                    $kind = $grant->source === GrantSource::Challenge ? AchievementsAnnouncedNotification::CHALLENGE : AchievementsAnnouncedNotification::RARE;
                    // Got in several seasons of one month: once, as a challenge one if any was
                    $known = $byMonth[$month][(string) $ownerId][$achievementId] ?? null;
                    $byMonth[$month][(string) $ownerId][$achievementId] = $known === AchievementsAnnouncedNotification::CHALLENGE ? $known : $kind;
                }
            }

            ksort($byMonth);

            foreach ($byMonth as $month => $entries) {
                $this->queue((string) $month, $entries);
            }
        });
    }

    /**
     * Nothing is taken: tried again later and, after the last try, failed so it is seen.
     */
    private function waitForReadableSettings(): void
    {
        Log::warning('The monthly achievement announcements wait: the settings could not be read.');

        if ($this->job === null) {
            return;
        }

        if ($this->attempts() >= $this->tries) {
            throw new AchievementSettingsUnreadableException('The achievement settings could not be read for the monthly announcements.');
        }

        $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
    }

    /**
     * @param  list<string>  $grantIds
     * @return list<AchievementGrant> the grants others may hear of
     */
    private function announced(
        string $ownerId,
        array $grantIds,
        AchievementGrantRepositoryInterface $grants,
        AchievementPreferencesRepositoryInterface $preferences,
        AchievementsSettings $settings,
        AchievementRarityDTO $rarity,
    ): array {
        $owner = UserModel::query()->find($ownerId);

        // Deactivated (not found) or anonymized: nothing is told about them
        if (! $owner instanceof UserModel || $owner->anonymized_at !== null) {
            return [];
        }

        $choices = $preferences->find($ownerId);

        if (! $choices->announceRare || $choices->hidden) {
            return [];
        }

        $wanted = array_flip($grantIds);

        // Any season: a repeatable achievement got again is told again
        return array_values(array_filter(
            $grants->forUser($ownerId),
            static fn (AchievementGrant $grant): bool => isset($wanted[strtolower($grant->id)]) && AnnouncementRule::announces(
                $grant->source,
                $grant->silent,
                $rarity->shareOf($grant->achievementId),
                $settings->announcementsRarityThreshold,
            ),
        ));
    }

    /**
     * Every account but the anonymized ones, in batches, queued once the transaction commits.
     * Owners are readers too: each one hears of the others.
     *
     * @param  array<string, array<string, string>>  $entries  owner id => achievement id => kind
     */
    private function queue(string $month, array $entries): void
    {
        UserModel::query()
            ->whereNull('anonymized_at')
            ->select('id')
            ->chunkById(DeliverAchievementAnnouncementsJob::BATCH_SIZE, static function (Collection $accounts) use ($month, $entries): void {
                /** @var list<string> $ids */
                $ids = array_map(strval(...), $accounts->modelKeys());

                DeliverAchievementAnnouncementsJob::dispatch($month, $entries, $ids)->afterCommit();
            });
    }
}
