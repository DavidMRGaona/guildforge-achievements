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
use Modules\Achievements\Domain\Enums\GrantLogAction;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\GrantReason;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use stdClass;

/**
 * Written with the query builder and `INSERT … ON CONFLICT DO NOTHING` (insertOrIgnore), so two jobs
 * granting the same achievement at once write one row and only one of them reports it as new (and
 * writes its history entry, in the same transaction).
 */
final readonly class EloquentAchievementGrantRepository implements AchievementGrantRepositoryInterface
{
    public const string TABLE = 'achievements_grants';

    public const string LOG_TABLE = 'achievements_grant_log';

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

        $actorId = $grant->actorId === null ? null : ActivityIdentifierRule::userId($grant->actorId);
        $reason = GrantReason::normalise($grant->reason);

        return $this->db->transaction(function () use ($grant, $userId, $achievementId, $actorId, $reason): ?AchievementGrant {
            // Shared locks: an anonymization (or a deletion of the achievement) running meanwhile
            // waits for this write, and then removes what it wrote
            $user = UserModel::withTrashed()->whereKey($userId)->sharedLock()->first(['id', 'anonymized_at']);

            if (! $user instanceof UserModel || $user->anonymized_at !== null) {
                return null;
            }

            $name = $this->db->table(self::CATALOGUE_TABLE)->where('id', $achievementId)->sharedLock()->value('name');

            if ($name === null) {
                return null;
            }

            $seasonKey = $grant->season ?? '';

            // Taken away by hand: only a person granting it again brings it back
            if ($grant->source !== GrantSource::Manual && $this->lastAction($userId, $achievementId, $seasonKey) === GrantLogAction::Revoked) {
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
                'season_key' => $seasonKey,
                'granted_at' => $stamp,
                'source' => $grant->source->value,
                'actor_id' => $actorId,
                'silent' => $grant->silent,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);

            if ($written !== 1) {
                return null;
            }

            // Stamped once written: its entry never sorts before a revoke that waited for this commit
            $this->log(GrantLogAction::Granted, $grant->source, $achievementId, (string) $name, $userId, $actorId, $seasonKey, $reason, $this->now());

            return new AchievementGrant(
                id: $id,
                userId: $userId,
                achievementId: $achievementId,
                season: $grant->season,
                grantedAt: new DateTimeImmutable($stamp, $now->getTimezone()),
                source: $grant->source,
                actorId: $actorId,
                silent: $grant->silent,
            );
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function find(string $grantId): ?AchievementGrant
    {
        if (! AchievementIds::isWellFormed($grantId)) {
            return null;
        }

        $row = $this->table()->where('id', strtolower($grantId))->first();

        return $row instanceof stdClass ? $this->toEntity($row) : null;
    }

    public function revoke(string $grantId, string $actorId, string $reason): ?AchievementGrant
    {
        $reason = GrantReason::normalise($reason);
        $actorId = ActivityIdentifierRule::userId($actorId);

        if ($reason === null || $actorId === null || ! AchievementIds::isWellFormed($grantId)) {
            return null;
        }

        return $this->db->transaction(function () use ($grantId, $actorId, $reason): ?AchievementGrant {
            $userId = $this->table()->where('id', strtolower($grantId))->value('user_id');

            if ($userId === null) {
                return null;
            }

            // The account first, then the grant, as everywhere else: a grant by a rule, a challenge
            // or in bulk holds the account's row with a shared lock while it reads the history, so
            // it either commits before this revoke (which then removes what it wrote) or waits for
            // it and finds the revoke. Anonymizing (deleteForUser()) takes the same lock.
            $this->db->table('users')->where('id', (string) $userId)->lockForUpdate()->first(['id']);
            $row = $this->table()->where('id', strtolower($grantId))->lockForUpdate()->first();

            if (! $row instanceof stdClass) {
                return null;
            }

            $grant = $this->toEntity($row);

            if (! $grant instanceof AchievementGrant) {
                return null;
            }

            // The grant's foreign key keeps the achievement while the row exists
            $name = (string) $this->db->table(self::CATALOGUE_TABLE)->where('id', $grant->achievementId)->value('name');

            $this->table()->where('id', $grant->id)->delete();

            $this->log(GrantLogAction::Revoked, GrantSource::Manual, $grant->achievementId, $name, $grant->userId, $actorId, (string) $row->season_key, $reason, $this->now());

            return $grant;
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function revokedAmong(string $achievementId, array $userIds): array
    {
        if (! AchievementIds::isWellFormed($achievementId)) {
            return [];
        }

        $userIds = array_values(array_unique(array_map(strtolower(...), $userIds)));
        $latest = [];

        foreach (array_chunk($userIds, 500) as $chunk) {
            $rows = $this->db->table(self::LOG_TABLE)
                ->where('achievement_id', strtolower($achievementId))
                ->where('season_key', '')
                ->whereIn('user_id', $chunk)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['user_id', 'action']);

            foreach ($rows as $row) {
                $latest[strtolower((string) $row->user_id)] = (string) $row->action;
            }
        }

        return array_keys(array_filter($latest, static fn (string $action): bool => $action === GrantLogAction::Revoked->value));
    }

    public function revokedAchievementIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map(strtolower(...), $userIds)));
        $latest = [];

        foreach (array_chunk($userIds, 500) as $chunk) {
            $rows = $this->db->table(self::LOG_TABLE)
                ->whereIn('user_id', $chunk)
                ->whereNotNull('achievement_id')
                ->where('season_key', '')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['user_id', 'achievement_id', 'action']);

            foreach ($rows as $row) {
                $latest[strtolower((string) $row->user_id)][strtolower((string) $row->achievement_id)] = (string) $row->action;
            }
        }

        $revoked = [];

        foreach ($latest as $userId => $actions) {
            $ids = array_keys(array_filter($actions, static fn (string $action): bool => $action === GrantLogAction::Revoked->value));

            if ($ids !== []) {
                $revoked[(string) $userId] = array_map(strval(...), $ids);
            }
        }

        return $revoked;
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
        $userId = strtolower($userId);

        return $this->db->transaction(function () use ($userId): int {
            // The account first, as revoke() does, so the two never wait on each other in a circle
            $this->db->table('users')->where('id', $userId)->lockForUpdate()->first(['id']);

            $this->db->table(self::LOG_TABLE)
                ->where('user_id', $userId)
                ->update(['user_id' => null, 'reason' => null]);

            return $this->table()->where('user_id', $userId)->delete();
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function now(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()))->format(self::STORAGE_FORMAT);
    }

    /**
     * The latest history entry of an account for an achievement (and season). uuid7 ids keep the
     * order of entries written within the same second.
     */
    private function lastAction(string $userId, string $achievementId, string $seasonKey): ?GrantLogAction
    {
        $action = $this->db->table(self::LOG_TABLE)
            ->where('user_id', $userId)
            ->where('achievement_id', $achievementId)
            ->where('season_key', $seasonKey)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('action');

        return $action === null ? null : GrantLogAction::tryFrom((string) $action);
    }

    private function log(
        GrantLogAction $action,
        GrantSource $source,
        string $achievementId,
        string $achievementName,
        string $userId,
        ?string $actorId,
        string $seasonKey,
        ?string $reason,
        string $stamp,
    ): void {
        $this->db->table(self::LOG_TABLE)->insert([
            'id' => (string) Str::uuid7(),
            'achievement_id' => $achievementId,
            'achievement_name' => mb_substr($achievementName, 0, 60),
            'user_id' => $userId,
            'actor_id' => $actorId,
            'action' => $action->value,
            'source' => $source->value,
            'season_key' => $seasonKey,
            'reason' => $reason,
            'created_at' => $stamp,
        ]);
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
