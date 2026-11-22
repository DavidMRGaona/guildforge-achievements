<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\SiteNotifications\SiteNotificationChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Achievements\Notifications\AchievementsAnnouncedNotification;
use Throwable;

/**
 * Delivers one announcement (achievements#6) to one batch of accounts.
 *
 * It goes straight to the core's site channel, which decides for each account (active, wants the
 * category), as the core's site news do: the notice is never mailed, and a test faking notifications
 * elsewhere does not see everybody's. The notification decides whether each one may see it.
 */
final class DeliverAchievementAnnouncementsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int BATCH_SIZE = 100;

    /** A retry would add the notice again to the accounts already reached */
    public int $tries = 1;

    /**
     * @param  list<string>  $achievementIds
     * @param  list<string>  $accountIds
     */
    public function __construct(
        public readonly string $ownerId,
        public readonly array $achievementIds,
        public readonly array $accountIds,
    ) {}

    public function handle(SiteNotificationChannel $channel): void
    {
        $notification = new AchievementsAnnouncedNotification($this->ownerId, $this->achievementIds);

        foreach (UserModel::query()->whereKey($this->accountIds)->get() as $account) {
            try {
                $channel->send($account, $notification);
            } catch (Throwable $exception) {
                // One account that cannot be reached does not leave the rest of the batch without it
                Log::warning('An achievement announcement could not be delivered.', [
                    'owner_id' => $this->ownerId,
                    'user_id' => $account->id,
                    'exception' => $exception::class,
                ]);
            }
        }
    }
}
