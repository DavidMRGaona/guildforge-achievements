<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Achievements\Application\DTOs\AchievementRarityDTO;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\Repositories\PendingAchievementNoticeRepositoryInterface;
use Modules\Achievements\Domain\Rules\AnnouncementRule;

/**
 * The daily release of announcements (achievements#6): every rare or challenge achievement got since
 * the last one, told to whoever may see its owner's profile.
 *
 * Others never learn when an achievement was got, only roughly the day: grants of one evaluation
 * share their second, so telling them at once would say who played with whom. So it runs once a day
 * (RELEASE_AT in the display zone) and each owner's announcement goes out at its own random moment
 * of the next SPREAD_SECONDS, never together with the others of the same table or event.
 *
 * It decides then, and fails closed: settings read strictly (unreadable: nothing is taken, tried
 * the next day), the mechanic on (off: what waited is dropped, never told later), the owner active,
 * allowing it and showing their achievements, and each grant a challenge one or rare now
 * (AnnouncementRule). The delivery asks again for each reader.
 */
final class ReleaseAchievementAnnouncementsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** When the day's announcements start going out, in the display zone */
    public const string RELEASE_AT = '10:00';

    /** Each owner's announcement goes out at a random moment within this */
    public const int SPREAD_SECONDS = 8 * 3600;

    /** A retry would announce nothing more: what was taken is gone */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(
        PendingAchievementNoticeRepositoryInterface $pending,
        AchievementGrantRepositoryInterface $grants,
        AchievementPreferencesRepositoryInterface $preferences,
        AchievementsSettingsInterface $settingsService,
        AchievementRarityInterface $rarityService,
    ): void {
        $settings = $settingsService->readStrictly();

        if (! $settings instanceof AchievementsSettings) {
            Log::warning('Achievement announcements wait for the next release: the settings could not be read.');

            return;
        }

        $waiting = $pending->claimAnnouncements();

        if ($waiting === [] || ! $settings->announcements) {
            return;
        }

        $rarity = $rarityService->current();

        foreach ($waiting as $ownerId => $grantIds) {
            $announced = $this->announced((string) $ownerId, $grantIds, $grants, $preferences, $settings, $rarity);

            if ($announced !== []) {
                $this->queue((string) $ownerId, $announced);
            }
        }
    }

    /**
     * @param  list<string>  $grantIds
     * @return list<string> achievement ids
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
        $ids = [];

        foreach ($grants->forUser($ownerId) as $grant) {
            if (isset($wanted[strtolower($grant->id)]) && $this->announces($grant, $settings, $rarity)) {
                $ids[strtolower($grant->achievementId)] = true;
            }
        }

        return array_keys($ids);
    }

    private function announces(AchievementGrant $grant, AchievementsSettings $settings, AchievementRarityDTO $rarity): bool
    {
        return AnnouncementRule::announces(
            $grant->source,
            $grant->silent,
            $rarity->shareOf($grant->achievementId),
            $settings->announcementsRarityThreshold,
        );
    }

    /**
     * Every account but the owner and the anonymized ones, in batches, all at the owner's own moment.
     *
     * @param  list<string>  $achievementIds
     */
    private function queue(string $ownerId, array $achievementIds): void
    {
        $at = now()->addSeconds(random_int(0, self::SPREAD_SECONDS));

        UserModel::query()
            ->whereNull('anonymized_at')
            ->whereKeyNot($ownerId)
            ->select('id')
            ->chunkById(DeliverAchievementAnnouncementsJob::BATCH_SIZE, static function (Collection $accounts) use ($ownerId, $achievementIds, $at): void {
                /** @var list<string> $ids */
                $ids = array_map(strval(...), $accounts->modelKeys());

                DeliverAchievementAnnouncementsJob::dispatch($ownerId, $achievementIds, $ids)->delay($at);
            });
    }
}
