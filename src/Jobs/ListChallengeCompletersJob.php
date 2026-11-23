<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Date;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Exceptions\AchievementSettingsUnreadableException;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Repositories\ChallengeRepositoryInterface;
use Modules\Achievements\Infrastructure\Challenges\ChallengeHolders;
use Throwable;

/**
 * The lists of who completed a challenge (#12) are made again once a month, at the hour of the
 * month's summary of announcements (ReleaseAchievementAnnouncementsJob::RELEASE_DAY at RELEASE_AT,
 * display zone): whoever added to a completed challenge and holds its reward then. Between two
 * runs nobody is added to a list, however many got the reward meanwhile, so a name that turns up
 * never tells when that person got it. The page still drops, as it draws the list, whoever no
 * longer holds the reward, hides their achievements or their profile.
 *
 * With the «Retos» switch off nothing is made (nothing is shown); settings that cannot be read are
 * tried again, and fail the job after its last try. A run that fails for good is made once more a
 * day later (`catchUp`), and switching «Retos» on makes one too, so the lists never wait a month.
 */
final class ListChallengeCompletersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [600, 3600, 21600];

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    /** A catch-up run waits this long: after the count that comes with switching «Retos» on */
    public const int CATCH_UP_DELAY_MINUTES = 15;

    /** After a monthly run failed for good, the catch-up waits a day */
    public const int CATCH_UP_AFTER_FAILURE_HOURS = 24;

    public function __construct(
        public readonly bool $catchUp = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->catchUp ? 'catch-up' : 'monthly';
    }

    /**
     * Once: a catch-up that fails too waits for the next 1st.
     */
    public function failed(?Throwable $exception = null): void
    {
        if (! $this->catchUp) {
            self::dispatch(catchUp: true)->delay(now()->addHours(self::CATCH_UP_AFTER_FAILURE_HOURS));
        }
    }

    public function handle(
        AchievementsSettingsInterface $settings,
        ChallengeRepositoryInterface $challenges,
        ChallengeHolders $holders,
    ): void {
        $read = $settings->readStrictly();

        if (! $read instanceof AchievementsSettings) {
            throw AchievementSettingsUnreadableException::afterRetries();
        }

        if (! $read->challenges) {
            return;
        }

        $now = DateTimeImmutable::createFromInterface(Date::now());

        foreach ($challenges->completed() as $challenge) {
            $challenges->replaceListed(
                $challenge->id,
                $holders->among($challenge->rewardAchievementId, $challenges->contributorIds($challenge->id)),
                $now,
            );
        }
    }
}
