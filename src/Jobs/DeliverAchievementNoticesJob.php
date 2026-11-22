<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\Repositories\PendingAchievementNoticeRepositoryInterface;
use Modules\Achievements\Domain\Rules\AnnouncementRule;
use Modules\Achievements\Notifications\AchievementsEarnedNotification;

/**
 * Tells one person, in one notice, every achievement they got since the last delivery
 * (achievements#6), and queues the announcement of the rare ones to everybody else.
 *
 * Each grant queues it a little later (DELAY_SECONDS) and only once while one is waiting for that
 * person (unique until it starts), so an evaluation that grants hundreds gives one notice. It takes
 * the waiting grants and leaves none, so running twice tells nothing twice, and a retry after a
 * failure before taking them loses nothing.
 */
final class DeliverAchievementNoticesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Long enough for one evaluation to finish granting */
    public const int DELAY_SECONDS = 60;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** A lock left by a lost job ends after an hour */
    public int $uniqueFor = 3600;

    public readonly string $userId;

    public function __construct(string $userId)
    {
        $this->userId = strtolower($userId);
    }

    public function uniqueId(): string
    {
        return 'achievements:notices:'.$this->userId;
    }

    public function handle(
        PendingAchievementNoticeRepositoryInterface $pending,
        AchievementGrantRepositoryInterface $grants,
    ): void {
        $grantIds = $pending->claim($this->userId);

        if ($grantIds === []) {
            return;
        }

        $owner = UserModel::query()->find($this->userId);

        // Deactivated (not found) or anonymized: nobody to tell, and nothing to tell about them
        if (! $owner instanceof UserModel || $owner->anonymized_at !== null) {
            return;
        }

        $byId = [];

        foreach ($grants->forUser($this->userId) as $grant) {
            $byId[strtolower($grant->id)] = $grant;
        }

        /** @var list<AchievementGrant> $told */
        $told = array_values(array_filter(array_map(static fn (string $id): ?AchievementGrant => $byId[$id] ?? null, $grantIds)));

        if ($told === []) {
            return;
        }

        $owner->notify(new AchievementsEarnedNotification($this->achievementIds($told)));

        $announced = $this->announced($told);

        if ($announced === []) {
            return;
        }

        UserModel::query()
            ->whereNull('anonymized_at')
            ->whereKeyNot($this->userId)
            ->select('id')
            ->chunkById(DeliverAchievementAnnouncementsJob::BATCH_SIZE, function (Collection $accounts) use ($announced): void {
                /** @var list<string> $ids */
                $ids = array_map(strval(...), $accounts->modelKeys());

                DeliverAchievementAnnouncementsJob::dispatch($this->userId, $announced, $ids);
            });
    }

    /**
     * The grants others may hear of, as decided now: the mechanic on (settings read strictly, so an
     * unreadable row announces nothing), the owner allowing it and showing their achievements, and
     * each grant a challenge one or rare (AnnouncementRule).
     *
     * @param  list<AchievementGrant>  $grants
     * @return list<string> achievement ids
     */
    private function announced(array $grants): array
    {
        $settings = app(AchievementsSettingsInterface::class)->readStrictly();

        if (! $settings instanceof AchievementsSettings || ! $settings->announcements) {
            return [];
        }

        $preferences = app(AchievementPreferencesRepositoryInterface::class)->find($this->userId);

        if (! $preferences->announceRare || $preferences->hidden) {
            return [];
        }

        $rarity = app(AchievementRarityInterface::class)->current();

        return $this->achievementIds(array_values(array_filter(
            $grants,
            static fn (AchievementGrant $grant): bool => AnnouncementRule::announces(
                $grant->source,
                $grant->silent,
                $rarity->shareOf($grant->achievementId),
                $settings->announcementsRarityThreshold,
            ),
        )));
    }

    /**
     * @param  list<AchievementGrant>  $grants
     * @return list<string>
     */
    private function achievementIds(array $grants): array
    {
        return array_values(array_unique(array_map(static fn (AchievementGrant $grant): string => strtolower($grant->achievementId), $grants)));
    }
}
