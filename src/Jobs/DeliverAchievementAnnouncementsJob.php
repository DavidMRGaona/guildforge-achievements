<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Exceptions\AchievementSettingsUnreadableException;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Notifications\AchievementsAnnouncedNotification;
use Throwable;

/**
 * Delivers one month's summary of announcements (achievements#6) to one batch of accounts, through
 * Laravel's notifications and the core's site channel, which decides for each account (active,
 * wants the category). The notification decides what each one may see.
 *
 * No duplicates before completeness: everything the batch shares (the settings, read strictly, and
 * what is about each owner and what the readers hold) is read before anything is sent, and anything
 * that fails there is tried again (unreadable settings are released, and fail loudly at the last
 * try), since nobody has heard of it yet. Once sending starts nothing leaves the job: an account
 * that cannot be reached is logged and skipped, never sent again by a retry to the others.
 */
final class DeliverAchievementAnnouncementsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int BATCH_SIZE = 100;

    /** Only what fails before anything is sent is tried again */
    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [600, 3600, 21600];

    /**
     * @param  string  $month  `Y-m` of the display zone, the month the summary is about
     * @param  array<string, array<string, string>>  $entries  owner id => achievement id => kind (rare, challenge)
     * @param  list<string>  $accountIds
     */
    public function __construct(
        public readonly string $month,
        public readonly array $entries,
        public readonly array $accountIds,
    ) {}

    public function handle(AchievementsSettingsInterface $settingsService): void
    {
        $settings = $settingsService->readStrictly();

        if (! $settings instanceof AchievementsSettings) {
            $this->waitForReadableSettings();

            return;
        }

        $notification = new AchievementsAnnouncedNotification($this->month, $this->entries);
        // What may fail for the whole batch fails here, before anybody is told
        $notification->prepare($settings, $this->accountIds);

        if (! $notification->hasAnything()) {
            return;
        }

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

    private function waitForReadableSettings(): void
    {
        Log::warning('A batch of the monthly achievement announcements waits: the settings could not be read.', ['month' => $this->month]);

        if ($this->job === null) {
            return;
        }

        if ($this->attempts() >= $this->tries) {
            throw new AchievementSettingsUnreadableException('The achievement settings could not be read to deliver the monthly announcements.');
        }

        $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
    }
}
