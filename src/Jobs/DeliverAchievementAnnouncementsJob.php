<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Achievements\Notifications\AchievementsAnnouncedNotification;
use Throwable;

/**
 * Delivers one month's summary of announcements (achievements#6) to one batch of accounts, through
 * Laravel's notifications and the core's site channel, which decides for each account (active,
 * wants the category). The notification decides what each one may see.
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
     * @param  string  $month  `Y-m` of the display zone, the month the summary is about
     * @param  array<string, list<string>>  $entries  owner id => achievement ids
     * @param  list<string>  $accountIds
     */
    public function __construct(
        public readonly string $month,
        public readonly array $entries,
        public readonly array $accountIds,
    ) {}

    public function handle(): void
    {
        $notification = new AchievementsAnnouncedNotification($this->month, $this->entries);
        $notification->forReaders($this->accountIds);

        foreach (UserModel::query()->whereKey($this->accountIds)->get() as $account) {
            try {
                $account->notify($notification);
            } catch (Throwable $exception) {
                // One account that cannot be reached does not leave the rest of the batch without it
                Log::warning('An achievement announcement could not be delivered.', [
                    'month' => $this->month,
                    'user_id' => $account->id,
                    'exception' => $exception::class,
                ]);
            }
        }
    }
}
