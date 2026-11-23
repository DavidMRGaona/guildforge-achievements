<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\PendingAchievementNoticeRepositoryInterface;
use Modules\Achievements\Notifications\AchievementsEarnedNotification;

/**
 * Tells one person, in one notice, every achievement they got since the last delivery
 * (achievements#6). What others hear goes out apart, in the monthly summary
 * (ReleaseAchievementAnnouncementsJob), so it never says when it was got.
 *
 * Each grant queues it a little later (DELAY_SECONDS) and only once while one is waiting for that
 * person (unique until it starts), so an evaluation that grants hundreds gives one notice. It takes
 * the waiting grants only once the notice is stored, in one transaction: running twice tells
 * nothing twice, and a notice that fails leaves them all for the retry.
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
        // What waits is forgotten only once told: a notice that fails leaves it all for the retry
        $pending->deliverEarned($this->userId, function (array $grantIds) use ($grants): void {
            $owner = UserModel::query()->find($this->userId);

            // Deactivated (not found) or anonymized: nobody to tell
            if (! $owner instanceof UserModel || $owner->anonymized_at !== null) {
                return;
            }

            $byId = [];

            foreach ($grants->forUser($this->userId) as $grant) {
                $byId[strtolower($grant->id)] = $grant;
            }

            $achievementIds = [];

            foreach ($grantIds as $grantId) {
                $grant = $byId[$grantId] ?? null;

                if ($grant instanceof AchievementGrant) {
                    $achievementIds[strtolower($grant->achievementId)] = true;
                }
            }

            // Built first: what fails while building fails the job, the channel would swallow it
            $notification = AchievementsEarnedNotification::for($owner, array_keys($achievementIds));

            if ($notification instanceof AchievementsEarnedNotification) {
                $owner->notify($notification);
            }
        });
    }
}
