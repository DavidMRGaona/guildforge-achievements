<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use App\Application\Activity\DTOs\ActivityFactDTO;
use App\Domain\Activity\ActivityRole;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\ActivityLedgerInterface;
use Modules\Achievements\Application\Services\RecognitionsInterface;
use Tests\Support\Activity\InMemoryActivitySource;

/**
 * Games played together (the core's in-memory source, a `game_session` source) and recognitions.
 * Instants are written in UTC; the tests store in UTC and show in Madrid.
 */
trait BuildsRecognitions
{
    protected function switchRecognitions(bool $on = true, int $days = 14): void
    {
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(recognitions: $on, recognitionsWindowDays: $days));
        $this->app->forgetScopedInstances();
    }

    /**
     * The game as its source reports it now, recorded in the ledger as a source event would.
     */
    protected function played(string $subjectId, string $utc, UserModel ...$people): void
    {
        InMemoryActivitySource::put($subjectId, array_values(array_map(
            static fn (UserModel $person): ActivityFactDTO => new ActivityFactDTO((string) $person->id, ActivityRole::Player, new DateTimeImmutable($utc, new DateTimeZone('UTC'))),
            $people,
        )));
        $this->app->make(ActivityLedgerInterface::class)->refreshSubject(InMemoryActivitySource::TYPE, $subjectId);
    }

    protected function typeId(string $starterKey = 'great_storytelling'): string
    {
        return strtolower((string) DB::table('achievements_recognition_types')->where('starter_key', $starterKey)->value('id'));
    }

    protected function recognitions(): RecognitionsInterface
    {
        $this->app->forgetScopedInstances();

        return $this->app->make(RecognitionsInterface::class);
    }

    protected function recognitionCount(): int
    {
        return DB::table('achievements_recognitions')->count();
    }
}
