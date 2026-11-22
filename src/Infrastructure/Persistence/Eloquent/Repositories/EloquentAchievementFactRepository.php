<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;
use Modules\Achievements\Domain\Entities\AchievementFact;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\FactAttribute;
use Modules\Achievements\Domain\ValueObjects\FactsReplacement;
use Modules\Achievements\Domain\ValueObjects\RecordedFact;
use stdClass;

/**
 * Written with the query builder: a subject is replaced with a few statements, and instants are
 * formatted in the storage zone (PHP's default zone) by hand, since bindings skip the models'
 * conversion.
 */
final readonly class EloquentAchievementFactRepository implements AchievementFactRepositoryInterface
{
    public const string TABLE = 'achievements_facts';

    public const string LOCKS_TABLE = 'achievements_subject_locks';

    private const int TRANSACTION_ATTEMPTS = 3;

    private const string STORAGE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function withSubjectLock(string $sourceType, string $subjectId, Closure $work): mixed
    {
        return $this->db->transaction(function () use ($sourceType, $subjectId, $work): mixed {
            $key = ['source_type' => $sourceType, 'subject_id' => $subjectId];

            // The row exists before it is locked, so a subject with no facts yet is locked too.
            // PostgreSQL waits on the row lock; SQLite takes the database's write lock with the insert.
            $this->db->table(self::LOCKS_TABLE)->insertOrIgnore($key);
            $this->db->table(self::LOCKS_TABLE)->where($key)->lockForUpdate()->first();

            return $work();
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function replaceSubject(string $sourceType, string $subjectId, string $kind, array $facts): FactsReplacement
    {
        return $this->db->transaction(function () use ($sourceType, $subjectId, $kind, $facts): FactsReplacement {
            $stored = $this->storedRows($sourceType, $subjectId);
            $wanted = $this->wantedRows($kind, $this->withoutAnonymizedAccounts($facts, lock: true));
            $replacement = $this->compare($stored, $wanted);

            if (! $replacement->changed) {
                return $replacement;
            }

            $gone = array_values(array_map(
                static fn (stdClass $row): string => (string) $row->id,
                array_diff_key($stored, $wanted),
            ));

            foreach (array_chunk($gone, 500) as $ids) {
                $this->table()->whereIn('id', $ids)->delete();
            }

            $now = new DateTimeImmutable()->format(self::STORAGE_FORMAT);
            $rows = [];

            foreach ($wanted as $key => $row) {
                $current = $stored[$key] ?? null;

                if ($current instanceof stdClass && $this->sameRow($current, $row)) {
                    continue;
                }

                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'source_type' => $sourceType,
                    'subject_id' => $subjectId,
                    ...$row,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // An upsert keeps the row (and its id) of a fact whose details changed
            foreach (array_chunk($rows, 200) as $chunk) {
                $this->table()->upsert(
                    $chunk,
                    ['source_type', 'subject_id', 'user_id', 'role'],
                    ['kind', 'occurred_at', 'attributes', 'placement', 'updated_at'],
                );
            }

            return $replacement;
        });
    }

    public function previewReplacement(string $sourceType, string $subjectId, string $kind, array $facts): FactsReplacement
    {
        return $this->compare(
            $this->storedRows($sourceType, $subjectId),
            $this->wantedRows($kind, $this->withoutAnonymizedAccounts($facts, lock: false)),
        );
    }

    public function subjectIds(string $sourceType): array
    {
        return array_values(array_map(
            strval(...),
            $this->table()
                ->where('source_type', $sourceType)
                ->distinct()
                ->orderBy('subject_id')
                ->pluck('subject_id')
                ->all(),
        ));
    }

    public function recentSubjectIds(string $sourceType, int $limit): array
    {
        return array_values(array_map(
            strval(...),
            $this->table()
                ->where('source_type', $sourceType)
                ->groupBy('subject_id')
                ->orderByRaw('MAX(occurred_at) DESC')
                ->orderBy('subject_id')
                ->limit(max(1, $limit))
                ->pluck('subject_id')
                ->all(),
        ));
    }

    public function userIdsOfSubject(string $sourceType, string $subjectId): array
    {
        return array_values(array_map(
            strval(...),
            $this->table()
                ->where('source_type', $sourceType)
                ->where('subject_id', $subjectId)
                ->distinct()
                ->orderBy('user_id')
                ->pluck('user_id')
                ->all(),
        ));
    }

    public function forUser(string $userId, array $kinds = []): array
    {
        $query = $this->table()
            ->where('user_id', strtolower($userId))
            ->orderBy('occurred_at')
            ->orderBy('source_type')
            ->orderBy('subject_id');

        if ($kinds !== []) {
            $query->whereIn('kind', $kinds);
        }

        $facts = [];

        foreach ($query->get() as $row) {
            $facts[] = $this->toEntity($row);
        }

        return $facts;
    }

    public function forUsers(array $userIds, array $kinds = []): array
    {
        $userIds = array_values(array_unique(array_map(strtolower(...), $userIds)));
        $facts = [];

        foreach (array_chunk($userIds, 500) as $chunk) {
            $query = $this->table()
                ->whereIn('user_id', $chunk)
                ->orderBy('occurred_at')
                ->orderBy('source_type')
                ->orderBy('subject_id');

            if ($kinds !== []) {
                $query->whereIn('kind', $kinds);
            }

            foreach ($query->get() as $row) {
                $fact = $this->toEntity($row);
                $facts[strtolower($fact->userId)][] = $fact;
            }
        }

        return $facts;
    }

    public function userIdsWithKinds(array $kinds, ?string $afterUserId, int $limit): array
    {
        if ($kinds === [] || $limit < 1) {
            return [];
        }

        return array_values(array_map(
            strval(...),
            $this->table()
                ->whereIn('kind', $kinds)
                ->when($afterUserId !== null, static fn (Builder $query): Builder => $query->where('user_id', '>', strtolower((string) $afterUserId)))
                ->distinct()
                ->orderBy('user_id')
                ->limit($limit)
                ->pluck('user_id')
                ->all(),
        ));
    }

    public function sourceTypesOfKind(string $kind): array
    {
        return array_values(array_map(
            strval(...),
            $this->table()->where('kind', $kind)->distinct()->orderBy('source_type')->pluck('source_type')->all(),
        ));
    }

    public function countForUser(string $userId, string $kind, ?string $role = null): int
    {
        return $this->table()
            ->where('user_id', strtolower($userId))
            ->where('kind', $kind)
            ->when($role !== null, static fn (Builder $query): Builder => $query->where('role', $role))
            ->count();
    }

    public function firstMeetings(array $userIds, string $kind, ?string $role, ?string $withRole): array
    {
        $meetings = [];

        foreach (array_chunk($this->userIds(array_map(strtolower(...), $userIds)), 500) as $chunk) {
            $rows = $this->db->table(self::TABLE.' as mine')
                ->join(self::TABLE.' as others', static function (JoinClause $join): void {
                    $join->on('others.source_type', '=', 'mine.source_type')
                        ->on('others.subject_id', '=', 'mine.subject_id')
                        ->on('others.user_id', '<>', 'mine.user_id');
                })
                // Anonymized or deleted for good: never counted, even before the ledger forgets them
                ->join('users', 'users.id', '=', 'others.user_id')
                ->whereNull('users.anonymized_at')
                ->whereIn('mine.user_id', $chunk)
                ->where('mine.kind', $kind)
                ->when($role !== null, static fn (Builder $query): Builder => $query->where('mine.role', $role))
                ->when($withRole !== null, static fn (Builder $query): Builder => $query->where('others.role', $withRole))
                ->groupBy('mine.user_id', 'others.user_id')
                ->select('mine.user_id as user_id')
                ->selectRaw('min(mine.occurred_at) as first_at')
                ->get();

            $this->collectInstants($rows, $meetings);
        }

        return $this->sortedInstants($meetings);
    }

    public function newcomersWelcomed(array $userIds, string $kind, string $hostRole, string $newcomerRole): array
    {
        $newcomers = [];

        foreach (array_chunk($this->userIds(array_map(strtolower(...), $userIds)), 500) as $chunk) {
            $rows = $this->db->table(self::TABLE.' as host')
                ->join(self::TABLE.' as newcomer', static function (JoinClause $join): void {
                    $join->on('newcomer.source_type', '=', 'host.source_type')
                        ->on('newcomer.subject_id', '=', 'host.subject_id')
                        ->on('newcomer.user_id', '<>', 'host.user_id');
                })
                ->join('users', 'users.id', '=', 'newcomer.user_id')
                ->whereNull('users.anonymized_at')
                ->whereIn('host.user_id', $chunk)
                ->where('host.kind', $kind)
                ->where('host.role', $hostRole)
                ->where('newcomer.role', $newcomerRole)
                ->where('newcomer.occurred_at', '=', $this->firstFactOf('newcomer', $kind, $newcomerRole))
                ->groupBy('host.user_id', 'newcomer.user_id')
                ->select('host.user_id as user_id')
                ->selectRaw('min(newcomer.occurred_at) as first_at')
                ->get();

            $this->collectInstants($rows, $newcomers);
        }

        return $this->sortedInstants($newcomers);
    }

    public function hostsOfFirstFacts(array $userIds, string $kind, string $hostRole, string $newcomerRole): array
    {
        $hosts = [];

        foreach (array_chunk($this->userIds(array_map(strtolower(...), $userIds)), 500) as $chunk) {
            $rows = $this->db->table(self::TABLE.' as newcomer')
                ->join(self::TABLE.' as host', static function (JoinClause $join): void {
                    $join->on('host.source_type', '=', 'newcomer.source_type')
                        ->on('host.subject_id', '=', 'newcomer.subject_id')
                        ->on('host.user_id', '<>', 'newcomer.user_id');
                })
                ->whereIn('newcomer.user_id', $chunk)
                ->where('newcomer.kind', $kind)
                ->where('newcomer.role', $newcomerRole)
                ->where('host.role', $hostRole)
                ->where('newcomer.occurred_at', '=', $this->firstFactOf('newcomer', $kind, $newcomerRole))
                ->distinct()
                ->pluck('host.user_id');

            foreach ($rows as $hostId) {
                $hosts[strtolower((string) $hostId)] = true;
            }
        }

        return $this->userIds(array_keys($hosts));
    }

    public function deleteForUser(string $userId): int
    {
        return $this->table()->where('user_id', strtolower($userId))->delete();
    }

    /**
     * The facts were read before this transaction, and an account may have been anonymized since:
     * its facts are dropped. The accounts are read with a shared lock, so an anonymization that
     * starts meanwhile waits for this write to commit, and its UserAnonymized listener then
     * deletes what was written. Facts of an account that does not exist are kept, like the core's
     * reader keeps them.
     *
     * @param  list<RecordedFact>  $facts
     * @return list<RecordedFact>
     */
    private function withoutAnonymizedAccounts(array $facts, bool $lock): array
    {
        $userIds = array_values(array_unique(array_map(static fn (RecordedFact $fact): string => strtolower($fact->userId), $facts)));

        if ($userIds === []) {
            return [];
        }

        $anonymized = [];

        foreach (array_chunk($userIds, 500) as $chunk) {
            $query = UserModel::withTrashed()->whereIn('id', $chunk);

            // Locking reads every account of the subject, so none can turn anonymized meanwhile
            $lock ? $query->sharedLock() : $query->whereNotNull('anonymized_at');

            foreach ($query->get(['id', 'anonymized_at']) as $user) {
                if ($user->anonymized_at !== null) {
                    $anonymized[strtolower((string) $user->id)] = true;
                }
            }
        }

        return array_values(array_filter(
            $facts,
            static fn (RecordedFact $fact): bool => ! isset($anonymized[strtolower($fact->userId)]),
        ));
    }

    /**
     * The instant of the earliest fact of `$kind` in `$role` of the account in `$alias.user_id`: a
     * correlated subquery answered from the index on (user_id, kind, occurred_at).
     */
    private function firstFactOf(string $alias, string $kind, string $role): Closure
    {
        return static function (Builder $query) use ($alias, $kind, $role): void {
            $query->from(self::TABLE.' as earliest')
                ->selectRaw('min(earliest.occurred_at)')
                ->whereColumn('earliest.user_id', $alias.'.user_id')
                ->where('earliest.kind', $kind)
                ->where('earliest.role', $role);
        };
    }

    /**
     * @param  iterable<stdClass>  $rows  `user_id` and `first_at`, one per account and other account
     * @param  array<string, list<DateTimeImmutable>>  $instants
     */
    private function collectInstants(iterable $rows, array &$instants): void
    {
        $zone = new DateTimeZone(date_default_timezone_get());

        foreach ($rows as $row) {
            if ($row->first_at === null) {
                continue;
            }

            $instants[strtolower((string) $row->user_id)][] = new DateTimeImmutable($this->storedInstant($row->first_at), $zone);
        }
    }

    /**
     * @param  array<string, list<DateTimeImmutable>>  $instants
     * @return array<string, list<DateTimeImmutable>>
     */
    private function sortedInstants(array $instants): array
    {
        foreach ($instants as $userId => $list) {
            sort($list);
            $instants[$userId] = $list;
        }

        ksort($instants, SORT_STRING);

        return $instants;
    }

    private function table(): Builder
    {
        return $this->db->table(self::TABLE);
    }

    /**
     * @return array<string, stdClass> keyed by person and role
     */
    private function storedRows(string $sourceType, string $subjectId): array
    {
        $query = $this->table()
            ->where('source_type', $sourceType)
            ->where('subject_id', $subjectId);

        $rows = [];

        foreach ($query->get() as $row) {
            $rows[$this->key((string) $row->user_id, (string) $row->role)] = $row;
        }

        return $rows;
    }

    /**
     * The columns each fact is stored with, keyed like storedRows().
     *
     * @param  list<RecordedFact>  $facts
     * @return array<string, array{user_id: string, role: string, kind: string, occurred_at: string, attributes: string, placement: int|null}>
     */
    private function wantedRows(string $kind, array $facts): array
    {
        $zone = new DateTimeZone(date_default_timezone_get());
        $rows = [];

        foreach ($facts as $fact) {
            $userId = strtolower($fact->userId);
            $rows[$this->key($userId, $fact->role)] = [
                'user_id' => $userId,
                'role' => $fact->role,
                'kind' => $kind,
                'occurred_at' => $fact->occurredAt->setTimezone($zone)->format(self::STORAGE_FORMAT),
                'attributes' => $this->encodeAttributes($fact->attributes),
                'placement' => $fact->placement,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, stdClass>  $stored
     * @param  array<string, array{user_id: string, role: string, kind: string, occurred_at: string, attributes: string, placement: int|null}>  $wanted
     */
    private function compare(array $stored, array $wanted): FactsReplacement
    {
        $changed = array_diff_key($stored, $wanted) !== [] || array_diff_key($wanted, $stored) !== [];

        foreach ($wanted as $key => $row) {
            if ($changed) {
                break;
            }

            $changed = ! $this->sameRow($stored[$key], $row);
        }

        return new FactsReplacement(
            $this->userIds(array_map(static fn (stdClass $row): string => (string) $row->user_id, $stored)),
            $this->userIds(array_map(static fn (array $row): string => $row['user_id'], $wanted)),
            $changed,
        );
    }

    /**
     * @param  array{user_id: string, role: string, kind: string, occurred_at: string, attributes: string, placement: int|null}  $wanted
     */
    private function sameRow(stdClass $stored, array $wanted): bool
    {
        $placement = $stored->placement === null ? null : (int) $stored->placement;

        return (string) $stored->kind === $wanted['kind']
            && $this->storedInstant($stored->occurred_at) === $wanted['occurred_at']
            // Read back and written again, so key order and spacing of the stored JSON never count
            && $this->encodeAttributes($this->decodeAttributes($stored->attributes)) === $wanted['attributes']
            && $placement === $wanted['placement'];
    }

    /**
     * PostgreSQL may give a timestamp with fractions or an offset; only the second counts.
     */
    private function storedInstant(mixed $value): string
    {
        return substr((string) $value, 0, 19);
    }

    /**
     * @param  array<string|int, string>  $ids
     * @return list<string>
     */
    private function userIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);

        return $ids;
    }

    private function key(string $userId, string $role): string
    {
        return strtolower($userId).'|'.$role;
    }

    /**
     * @param  array<string, FactAttribute>  $attributes
     */
    private function encodeAttributes(array $attributes): string
    {
        if ($attributes === []) {
            return '{}';
        }

        ksort($attributes, SORT_STRING);
        $encoded = [];

        foreach ($attributes as $key => $value) {
            $encoded[$key] = ['id' => $value->id, 'label' => $value->label];
        }

        // A label with broken UTF-8 is stored with U+FFFD rather than losing the whole subject
        $json = json_encode($encoded, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    }

    /**
     * Tolerant: anything unreadable is no attributes.
     *
     * @return array<string, FactAttribute>
     */
    private function decodeAttributes(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        if (! is_array($decoded)) {
            return [];
        }

        $attributes = [];

        foreach ($decoded as $key => $attribute) {
            if (is_string($key) && is_array($attribute) && is_string($attribute['id'] ?? null) && is_string($attribute['label'] ?? null)) {
                $attributes[$key] = new FactAttribute($attribute['id'], $attribute['label']);
            }
        }

        ksort($attributes, SORT_STRING);

        return $attributes;
    }

    private function toEntity(stdClass $row): AchievementFact
    {
        return new AchievementFact(
            sourceType: (string) $row->source_type,
            subjectId: (string) $row->subject_id,
            userId: (string) $row->user_id,
            role: (string) $row->role,
            kind: (string) $row->kind,
            occurredAt: new DateTimeImmutable($this->storedInstant($row->occurred_at), new DateTimeZone(date_default_timezone_get())),
            attributes: $this->decodeAttributes($row->attributes),
            placement: $row->placement === null ? null : (int) $row->placement,
        );
    }
}
