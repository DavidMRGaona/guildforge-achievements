<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Closure;
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

    public const string AUDIENCE_TABLE = 'achievements_challenge_audience';

    public const string LISTED_TABLE = 'achievements_challenge_listed';

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

    public function saveProgress(string $id, ChallengeProgress $progress, DateTimeImmutable $countedAt, int $loadedVersion): ?Challenge
    {
        if (! AchievementIds::isWellFormed($id)) {
            return null;
        }

        return $this->db->transaction(function () use ($id, $progress, $countedAt, $loadedVersion): ?Challenge {
            $model = ChallengeModel::query()->whereKey(strtolower($id))->lockForUpdate()->first();

            if (! $model instanceof ChallengeModel || $model->closed_at !== null) {
                return null;
            }

            // What it counts changed since it was loaded: this count is of a version that no longer
            // exists (saving the new one queued another count); a new name changes nothing
            if ($model->count_version !== $loadedVersion) {
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

    public function publishShown(DateTimeImmutable $at): int
    {
        // Straight on the table: the shown columns are no change of what is counted (no new version)
        return ChallengeModel::query()
            ->whereNotNull('progress_at')
            ->toBase()
            ->update([
                'shown_progress' => $this->db->raw('progress'),
                'shown_contributors' => $this->db->raw('contributors'),
                'shown_completed_at' => $this->db->raw('completed_at'),
                'shown_at' => $this->stored($at),
            ]);
    }

    public function close(string $id, ?string $actorId, DateTimeImmutable $at): bool
    {
        if (! AchievementIds::isWellFormed($id)) {
            return false;
        }

        return (bool) $this->db->transaction(function () use ($id, $actorId, $at): bool {
            // Under the row lock: a count granting its rewards holds it too (whileOpen())
            $model = ChallengeModel::query()->whereKey(strtolower($id))->lockForUpdate()->first();

            if (! $model instanceof ChallengeModel || $model->closed_at !== null) {
                return false;
            }

            return ChallengeModel::query()->whereKey($model->id)->update([
                'closed_at' => $this->stored($at),
                'closed_by' => $actorId === null ? null : strtolower($actorId),
                'updated_at' => $this->stored($at),
            ]) > 0;
        });
    }

    public function whileOpen(string $id, Closure $work): bool
    {
        if (! AchievementIds::isWellFormed($id)) {
            return false;
        }

        return (bool) $this->db->transaction(static function () use ($id, $work): bool {
            $model = ChallengeModel::query()->whereKey(strtolower($id))->lockForUpdate()->first();

            if (! $model instanceof ChallengeModel || $model->closed_at !== null) {
                return false;
            }

            $work();

            return true;
        });
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

    public function deleteUnlessCompleted(string $id): bool
    {
        if (! AchievementIds::isWellFormed($id)) {
            return false;
        }

        return (bool) $this->db->transaction(static function () use ($id): bool {
            // Under the row lock, so a count that completes it meanwhile wins
            $model = ChallengeModel::query()->whereKey(strtolower($id))->lockForUpdate()->first();

            return $model instanceof ChallengeModel && $model->completed_at === null && $model->delete() === true;
        });
    }

    public function rewardingCounted(string $achievementId, string $today): array
    {
        if (! AchievementIds::isWellFormed($achievementId)) {
            return [];
        }

        $lastEnd = new DateTimeImmutable($today)->modify('-'.Challenge::LATE_FACTS_DAYS.' days')->format('Y-m-d');

        return array_values(array_map(
            strval(...),
            ChallengeModel::query()
                ->where('reward_achievement_id', strtolower($achievementId))
                ->whereNull('closed_at')
                ->whereDate('ends_on', '>=', $lastEnd)
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        ));
    }

    public function audienceOf(string $challengeId): array
    {
        if (! AchievementIds::isWellFormed($challengeId)) {
            return [];
        }

        $audience = [];

        foreach ($this->db->table(self::AUDIENCE_TABLE)->where('challenge_id', strtolower($challengeId))->get(['user_id', 'is_member']) as $row) {
            $audience[strtolower((string) $row->user_id)] = (bool) $row->is_member;
        }

        return $audience;
    }

    public function rememberAudience(string $challengeId, array $audience, bool $replace, DateTimeImmutable $at): void
    {
        if (! AchievementIds::isWellFormed($challengeId)) {
            return;
        }

        $challengeId = strtolower($challengeId);

        $this->db->transaction(function () use ($challengeId, $audience, $replace, $at): void {
            $table = $this->db->table(self::AUDIENCE_TABLE);

            if ($replace) {
                $table->clone()->where('challenge_id', $challengeId)->delete();
            }

            // An account anonymized meanwhile leaves no row: its accounts are read under a shared
            // lock, so an anonymization that starts now waits, and its listener then deletes them
            $present = [];

            foreach (array_chunk(array_map(strval(...), array_keys($audience)), self::CHUNK) as $chunk) {
                foreach (UserModel::withTrashed()->whereIn('id', $chunk)->whereNull('anonymized_at')->sharedLock()->pluck('id') as $id) {
                    $present[strtolower((string) $id)] = true;
                }
            }

            $rows = [];

            foreach ($audience as $userId => $isMember) {
                if (! isset($present[strtolower((string) $userId)])) {
                    continue;
                }

                $rows[] = [
                    'challenge_id' => $challengeId,
                    'user_id' => strtolower((string) $userId),
                    'is_member' => $isMember,
                    'created_at' => $this->stored($at),
                    'updated_at' => $this->stored($at),
                ];
            }

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                // Never changes who was already known: frozen once the days are over
                $table->clone()->insertOrIgnore($chunk);
            }
        });
    }

    public function forgetUser(string $userId): int
    {
        if (! AchievementIds::isWellFormed($userId)) {
            return 0;
        }

        $userId = strtolower($userId);

        return $this->db->transaction(function () use ($userId): int {
            $this->db->table(self::AUDIENCE_TABLE)->where('user_id', $userId)->delete();
            $this->db->table(self::LISTED_TABLE)->where('user_id', $userId)->delete();
            ChallengeModel::query()->where('closed_by', $userId)->update(['closed_by' => null]);
            ChallengeModel::query()->where('created_by', $userId)->update(['created_by' => null]);

            return $this->db->table(self::CONTRIBUTORS_TABLE)->where('user_id', $userId)->delete();
        });
    }

    public function completed(): array
    {
        return $this->entities(ChallengeModel::query()->whereNotNull('completed_at')->orderBy('starts_on')->orderBy('id')->get()->all());
    }

    public function listed(string $challengeId): array
    {
        if (! AchievementIds::isWellFormed($challengeId)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): string => strtolower((string) $id),
            $this->db->table(self::LISTED_TABLE)->where('challenge_id', strtolower($challengeId))->orderBy('user_id')->pluck('user_id')->all(),
        ));
    }

    public function replaceListed(string $challengeId, array $userIds, DateTimeImmutable $at): void
    {
        if (! AchievementIds::isWellFormed($challengeId)) {
            return;
        }

        $challengeId = strtolower($challengeId);
        $rows = array_map(fn (string $userId): array => [
            'challenge_id' => $challengeId,
            'user_id' => strtolower($userId),
            'listed_at' => $this->stored($at),
        ], array_values(array_unique(array_map(strtolower(...), $userIds))));

        $this->db->transaction(function () use ($challengeId, $rows): void {
            $table = $this->db->table(self::LISTED_TABLE);
            $table->clone()->where('challenge_id', $challengeId)->delete();

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $table->clone()->insert($chunk);
            }
        });
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
            progressAt: self::instant($model->progress_at),
            countVersion: $model->count_version,
            shownProgress: $model->shown_progress,
            shownContributors: $model->shown_contributors,
            shownCompletedAt: self::instant($model->shown_completed_at),
            shownAt: self::instant($model->shown_at),
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
