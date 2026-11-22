<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Activity\ActivityIdentifierRule;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use stdClass;

/**
 * Written with the query builder and `INSERT … ON CONFLICT DO NOTHING` (insertOrIgnore), so two jobs
 * granting the same achievement at once write one row and only one of them reports it as new.
 */
final readonly class EloquentAchievementGrantRepository implements AchievementGrantRepositoryInterface
{
    public const string TABLE = 'achievements_grants';

    private const string CATALOGUE_TABLE = 'achievements_catalogue';

    private const int TRANSACTION_ATTEMPTS = 3;

    private const string STORAGE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function grant(NewGrant $grant): ?AchievementGrant
    {
        $userId = ActivityIdentifierRule::userId($grant->userId);
        $achievementId = strtolower($grant->achievementId);

        if ($userId === null || ! AchievementIds::isWellFormed($achievementId)) {
            return null;
        }

        return $this->db->transaction(function () use ($grant, $userId, $achievementId): ?AchievementGrant {
            // Shared locks: an anonymization (or a deletion of the achievement) running meanwhile
            // waits for this write, and then removes what it wrote
            $user = UserModel::withTrashed()->whereKey($userId)->sharedLock()->first(['id', 'anonymized_at']);

            if (! $user instanceof UserModel || $user->anonymized_at !== null) {
                return null;
            }

            if (! $this->db->table(self::CATALOGUE_TABLE)->where('id', $achievementId)->sharedLock()->exists()) {
                return null;
            }

            $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
            $id = (string) Str::uuid7();
            $stamp = $now->format(self::STORAGE_FORMAT);

            $written = $this->table()->insertOrIgnore([
                'id' => $id,
                'user_id' => $userId,
                'achievement_id' => $achievementId,
                'season' => $grant->season,
                'season_key' => $grant->season ?? '',
                'granted_at' => $stamp,
                'source' => $grant->source->value,
                'actor_id' => $grant->actorId === null ? null : ActivityIdentifierRule::userId($grant->actorId),
                'silent' => $grant->silent,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);

            if ($written !== 1) {
                return null;
            }

            return new AchievementGrant(
                id: $id,
                userId: $userId,
                achievementId: $achievementId,
                season: $grant->season,
                grantedAt: new DateTimeImmutable($stamp, $now->getTimezone()),
                source: $grant->source,
                actorId: $grant->actorId === null ? null : ActivityIdentifierRule::userId($grant->actorId),
                silent: $grant->silent,
            );
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function heldAchievementIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map(strtolower(...), $userIds)));
        $held = [];

        foreach (array_chunk($userIds, 500) as $chunk) {
            $rows = $this->table()
                ->whereIn('user_id', $chunk)
                ->where('season_key', '')
                ->orderBy('achievement_id')
                ->get(['user_id', 'achievement_id']);

            foreach ($rows as $row) {
                $held[strtolower((string) $row->user_id)][] = strtolower((string) $row->achievement_id);
            }
        }

        return $held;
    }

    public function forUser(string $userId): array
    {
        $rows = $this->table()
            ->where('user_id', strtolower($userId))
            ->orderBy('granted_at')
            ->orderBy('id')
            ->get();

        $grants = [];

        foreach ($rows as $row) {
            $grant = $this->toEntity($row);

            if ($grant instanceof AchievementGrant) {
                $grants[] = $grant;
            }
        }

        return $grants;
    }

    public function forUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map(strtolower(...), $userIds)));
        $grants = [];

        foreach (array_chunk($userIds, 500) as $chunk) {
            $rows = $this->table()
                ->whereIn('user_id', $chunk)
                ->orderBy('granted_at')
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $grant = $this->toEntity($row);

                if ($grant instanceof AchievementGrant) {
                    $grants[strtolower($grant->userId)][] = $grant;
                }
            }
        }

        return $grants;
    }

    public function holds(string $userId, string $achievementId): bool
    {
        if (! AchievementIds::isWellFormed($achievementId)) {
            return false;
        }

        return $this->table()
            ->where('user_id', strtolower($userId))
            ->where('achievement_id', strtolower($achievementId))
            ->exists();
    }

    public function holderCounts(): array
    {
        $counts = [];

        $rows = $this->table()
            ->selectRaw('achievement_id, count(distinct user_id) as holders')
            ->groupBy('achievement_id')
            ->get();

        foreach ($rows as $row) {
            $counts[strtolower((string) $row->achievement_id)] = (int) $row->holders;
        }

        return $counts;
    }

    public function deleteForUser(string $userId): int
    {
        return $this->table()->where('user_id', strtolower($userId))->delete();
    }

    private function table(): Builder
    {
        return $this->db->table(self::TABLE);
    }

    /**
     * A row with a source this release does not know is left out rather than guessed.
     */
    private function toEntity(stdClass $row): ?AchievementGrant
    {
        $source = GrantSource::tryFrom((string) $row->source);

        if (! $source instanceof GrantSource) {
            return null;
        }

        return new AchievementGrant(
            id: (string) $row->id,
            userId: (string) $row->user_id,
            achievementId: (string) $row->achievement_id,
            season: $row->season === null ? null : (string) $row->season,
            grantedAt: new DateTimeImmutable(substr((string) $row->granted_at, 0, 19), new DateTimeZone(date_default_timezone_get())),
            source: $source,
            actorId: $row->actor_id === null ? null : (string) $row->actor_id,
            silent: (bool) $row->silent,
        );
    }
}
