<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Achievements\Domain\Challenges\ChallengeAudience;
use Modules\Achievements\Domain\Challenges\ChallengeProgress;
use Modules\Achievements\Domain\Challenges\ChallengeRule;
use Modules\Achievements\Domain\Entities\Challenge;
use Modules\Achievements\Domain\Repositories\ChallengeRepositoryInterface;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\ChallengeModel;

/**
 * Days are compared with whereDate(), which reads the day the same way in PostgreSQL and SQLite
 * (a `date` cast stores midnight with its time in SQLite). Contributions are written with the query
 * builder, their instants formatted in the storage zone (PHP's default zone) by hand, since
 * bindings skip the models' conversion.
 */
final readonly class EloquentChallengeRepository implements ChallengeRepositoryInterface
{
    public const string CONTRIBUTORS_TABLE = 'achievements_challenge_contributors';

    private const string STORAGE_FORMAT = 'Y-m-d H:i:s';

    private const int CHUNK = 500;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $id): ?Challenge
    {
        if (! AchievementIds::isWellFormed($id)) {
            return null;
        }

        $model = ChallengeModel::query()->find(strtolower($id));

        return $model instanceof ChallengeModel ? self::toEntity($model) : null;
    }

    public function all(): array
    {
        return $this->entities(ChallengeModel::query()->orderByDesc('starts_on')->orderBy('name')->orderBy('id')->get()->all());
    }

    public function counted(string $today, ?array $ids = null): array
    {
        if ($ids !== null) {
            $ids = array_values(array_filter(array_map(strtolower(...), $ids), AchievementIds::isWellFormed(...)));

            if ($ids === []) {
                return [];
            }
        }

        $lastEnd = new DateTimeImmutable($today)->modify('-'.Challenge::LATE_FACTS_DAYS.' days')->format('Y-m-d');

        return $this->entities(ChallengeModel::query()
            ->whereNull('closed_at')
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $lastEnd)
            ->when($ids !== null, static fn (Builder $query): Builder => $query->whereKey($ids))
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get()
            ->all());
    }

    public function shown(string $today, int $aheadDays, int $recentDays): array
    {
        $day = new DateTimeImmutable($today);

        return $this->entities(ChallengeModel::query()
            ->where(static fn (Builder $query): Builder => $query->whereNull('closed_at')->orWhereNotNull('completed_at'))
            ->whereDate('starts_on', '<=', $day->modify('+'.$aheadDays.' days')->format('Y-m-d'))
            ->whereDate('ends_on', '>=', $day->modify('-'.$recentDays.' days')->format('Y-m-d'))
            ->orderBy('ends_on')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->all());
    }

    public function saveProgress(string $id, ChallengeProgress $progress, DateTimeImmutable $countedAt): ?Challenge
    {
        if (! AchievementIds::isWellFormed($id)) {
            return null;
        }

        return $this->db->transaction(function () use ($id, $progress, $countedAt): ?Challenge {
            $model = ChallengeModel::query()->whereKey(strtolower($id))->lockForUpdate()->first();

            if (! $model instanceof ChallengeModel || $model->closed_at !== null) {
                return null;
            }

            $model->progress = $progress->total;
            $model->contributors = count($progress->contributions);
            $model->progress_at = Carbon::instance($countedAt);

            if ($model->completed_at === null && $progress->completedAt instanceof DateTimeImmutable) {
                $model->completed_at = Carbon::instance($progress->completedAt);
            }

            $model->save();
            $this->replaceContributions($model->id, $progress, $countedAt);

            return self::toEntity($model);
        });
    }

    public function close(string $id, ?string $actorId, DateTimeImmutable $at): bool
    {
        if (! AchievementIds::isWellFormed($id)) {
            return false;
        }

        return ChallengeModel::query()
            ->whereKey(strtolower($id))
            ->whereNull('closed_at')
            ->update([
                'closed_at' => $this->stored($at),
                'closed_by' => $actorId === null ? null : strtolower($actorId),
                'updated_at' => $this->stored($at),
            ]) > 0;
    }

    public function contributionsOf(string $userId, array $challengeIds): array
    {
        $challengeIds = array_values(array_filter(array_map(strtolower(...), $challengeIds), AchievementIds::isWellFormed(...)));

        if ($challengeIds === [] || ! AchievementIds::isWellFormed($userId)) {
            return [];
        }

        $contributions = array_fill_keys($challengeIds, 0);

        foreach ($this->db->table(self::CONTRIBUTORS_TABLE)->where('user_id', strtolower($userId))->whereIn('challenge_id', $challengeIds)->get(['challenge_id', 'contribution']) as $row) {
            $contributions[strtolower((string) $row->challenge_id)] = (int) $row->contribution;
        }

        return $contributions;
    }

    public function contributorIds(string $challengeId): array
    {
        if (! AchievementIds::isWellFormed($challengeId)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): string => strtolower((string) $id),
            $this->db->table(self::CONTRIBUTORS_TABLE)->where('challenge_id', strtolower($challengeId))->orderBy('user_id')->pluck('user_id')->all(),
        ));
    }

    public function rewarding(string $achievementId): array
    {
        if (! AchievementIds::isWellFormed($achievementId)) {
            return [];
        }

        return array_values(array_map(
            strval(...),
            ChallengeModel::query()->where('reward_achievement_id', strtolower($achievementId))->orderBy('id')->pluck('id')->all(),
        ));
    }

    public function forgetUser(string $userId): int
    {
        if (! AchievementIds::isWellFormed($userId)) {
            return 0;
        }

        return $this->db->table(self::CONTRIBUTORS_TABLE)->where('user_id', strtolower($userId))->delete();
    }

    public static function toEntity(ChallengeModel $model): Challenge
    {
        return new Challenge(
            id: $model->id,
            name: $model->name,
            description: $model->description,
            rule: ChallengeRule::tryFrom($model->getRawOriginal('rule')),
            audience: ChallengeAudience::tryFrom($model->audience) ?? ChallengeAudience::Members,
            startsOn: $model->starts_on->format('Y-m-d'),
            endsOn: $model->ends_on->format('Y-m-d'),
            rewardAchievementId: strtolower($model->reward_achievement_id),
            progress: $model->progress,
            contributors: $model->contributors,
            completedAt: self::instant($model->completed_at),
            closedAt: self::instant($model->closed_at),
        );
    }

    /**
     * The rows of people who no longer add are removed; the rest are written as they are now.
     */
    private function replaceContributions(string $challengeId, ChallengeProgress $progress, DateTimeImmutable $at): void
    {
        $table = $this->db->table(self::CONTRIBUTORS_TABLE);
        $wanted = $progress->contributions;
        $stored = array_map(
            static fn (mixed $id): string => strtolower((string) $id),
            $table->clone()->where('challenge_id', $challengeId)->pluck('user_id')->all(),
        );
        $gone = array_values(array_diff($stored, array_keys($wanted)));

        foreach (array_chunk($gone, self::CHUNK) as $chunk) {
            $table->clone()->where('challenge_id', $challengeId)->whereIn('user_id', $chunk)->delete();
        }

        $rows = [];

        foreach ($wanted as $userId => $contribution) {
            $last = $progress->lastContributionOf((string) $userId) ?? $at;
            $rows[] = [
                'challenge_id' => $challengeId,
                'user_id' => (string) $userId,
                'contribution' => $contribution,
                'last_contribution_at' => $this->stored($last),
                'created_at' => $this->stored($at),
                'updated_at' => $this->stored($at),
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $table->clone()->upsert($chunk, ['challenge_id', 'user_id'], ['contribution', 'last_contribution_at', 'updated_at']);
        }
    }

    private function stored(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format(self::STORAGE_FORMAT);
    }

    private static function instant(?Carbon $value): ?DateTimeImmutable
    {
        return $value instanceof Carbon ? DateTimeImmutable::createFromInterface($value) : null;
    }

    /**
     * @param  array<ChallengeModel>  $models
     * @return list<Challenge>
     */
    private function entities(array $models): array
    {
        return array_values(array_map(self::toEntity(...), $models));
    }
}
