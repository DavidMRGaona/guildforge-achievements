<?php

declare(strict_types=1);

namespace Modules\Achievements\Jobs;

use App\Application\Services\TimezoneServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Modules\Achievements\Application\DTOs\RecognitionSubjectDTO;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\RecognitionsInterface;
use Modules\Achievements\Domain\Recognitions\RecognitionWindow;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Repositories\RecognitionRepositoryInterface;
use Modules\Achievements\Notifications\RecognitionPromptNotification;
use Throwable;

/**
 * Once a day (achievements:recognition-prompts, in the display zone): every person with a session
 * or a campaign still within the window they were not asked about yet, and somebody in it to
 * recognise, gets one «¿Quieres reconocer a alguien de…?» about all of them. Each subject is asked
 * about once; a person whose subjects cannot be read now is asked another day.
 */
final class SendRecognitionPromptsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function handle(
        AchievementsSettingsInterface $settings,
        RecognitionsInterface $recognitions,
        AchievementFactRepositoryInterface $facts,
        RecognitionRepositoryInterface $repository,
        TimezoneServiceInterface $time,
    ): void {
        if (! $recognitions->available()) {
            return;
        }

        $now = DateTimeImmutable::createFromInterface(Date::now());
        $since = RecognitionWindow::opensSince($now, $time->displayTimezone(), $settings->recognitionsWindowDays());
        $byPerson = [];

        foreach (RecognitionWindow::KINDS as $kind) {
            foreach ($facts->forKindBetween($kind, $since, $now) as $fact) {
                $byPerson[$fact->userId][$fact->sourceType."\0".$fact->subjectId] = [$fact->sourceType, $fact->subjectId];
            }
        }

        foreach ($byPerson as $userId => $subjects) {
            try {
                $this->prompt((string) $userId, array_values($subjects), $recognitions, $repository, $now, $time);
            } catch (Throwable $exception) {
                Log::warning('[Achievements] A recognition prompt could not be sent; it is tried again tomorrow.', [
                    'user_id' => $userId,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    /**
     * @param  list<array{string, string}>  $subjects
     */
    private function prompt(string $userId, array $subjects, RecognitionsInterface $recognitions, RecognitionRepositoryInterface $repository, DateTimeImmutable $now, TimezoneServiceInterface $time): void
    {
        $asked = [];

        foreach ($repository->prompted($userId, $subjects) as [$type, $id]) {
            $asked[$type."\0".$id] = true;
        }

        if (count($asked) === count($subjects)) {
            return;
        }

        $person = UserModel::query()->find($userId);

        if (! $person instanceof UserModel || $person->anonymized_at !== null) {
            return;
        }

        $open = array_values(array_filter(
            $recognitions->openSubjects($person),
            static fn (RecognitionSubjectDTO $subject): bool => ! isset($asked[$subject->sourceType."\0".$subject->subjectId])
                && array_any($subject->people, static fn ($one): bool => $one->givenTypeId === null),
        ));

        if ($open === []) {
            return;
        }

        $lastDay = max(array_map(static fn (RecognitionSubjectDTO $subject): string => $subject->closesOn, $open));
        $person->notify(RecognitionPromptNotification::for($open, new DateTimeImmutable($lastDay, $time->displayTimezone())->format('d/m/Y')));
        $repository->markPrompted($userId, array_map(static fn (RecognitionSubjectDTO $subject): array => [$subject->sourceType, $subject->subjectId], $open), $now);
    }
}
