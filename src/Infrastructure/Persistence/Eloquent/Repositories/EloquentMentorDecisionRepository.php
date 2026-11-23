<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Services\TimezoneServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Modules\Achievements\Domain\Repositories\MentorDecisionRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\MentorDecision;
use stdClass;

/**
 * Written with the query builder, so instants go through the storage zone by hand
 * (TimezoneServiceInterface), like the ledger.
 */
final readonly class EloquentMentorDecisionRepository implements MentorDecisionRepositoryInterface
{
    public const string TABLE = 'achievements_mentor_decisions';

    private const string STORAGE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private ConnectionInterface $db,
        private TimezoneServiceInterface $time,
    ) {}

    public function undecidedNewcomers(string $kind, string $role, ?string $afterUserId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): string => strtolower((string) $id),
            $this->db->table(EloquentAchievementFactRepository::TABLE.' as fact')
                ->join('users', 'users.id', '=', 'fact.user_id')
                ->leftJoin(self::TABLE.' as decision', static function (JoinClause $join): void {
                    $join->on('decision.newcomer_id', '=', 'fact.user_id');
                })
                ->whereNull('users.anonymized_at')
                ->whereNull('decision.newcomer_id')
                ->where('fact.kind', $kind)
                ->where('fact.role', $role)
                ->when($afterUserId !== null, static fn (Builder $query): Builder => $query->where('fact.user_id', '>', strtolower((string) $afterUserId)))
                ->distinct()
                ->orderBy('fact.user_id')
                ->limit($limit)
                ->pluck('fact.user_id')
                ->all(),
        ));
    }

    public function record(array $decisions): int
    {
        if ($decisions === []) {
            return 0;
        }

        return $this->db->transaction(function () use ($decisions): int {
            // The accounts are read with a shared lock: an anonymization that starts meanwhile waits
            // for this write, and its listener then erases or empties what was written; one done
            // before is seen here, so its newcomer is left out and its mentor written as nobody
            $live = $this->liveAccounts(array_merge(
                array_map(static fn (MentorDecision $decision): string => $decision->newcomerId, $decisions),
                array_filter(array_map(static fn (MentorDecision $decision): ?string => $decision->mentorId, $decisions)),
            ));
            $now = $this->time->toStorage(new DateTimeImmutable('now'))->format(self::STORAGE_FORMAT);
            $rows = [];

            foreach ($decisions as $decision) {
                $newcomerId = strtolower($decision->newcomerId);
                $mentorId = $decision->mentorId === null ? null : strtolower($decision->mentorId);

                if (! isset($live[$newcomerId])) {
                    continue;
                }

                $rows[] = [
                    'newcomer_id' => $newcomerId,
                    'mentor_id' => $mentorId !== null && isset($live[$mentorId]) ? $mentorId : null,
                    'decided_at' => $this->time->toStorage($decision->decidedAt)->format(self::STORAGE_FORMAT),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $written = 0;

            foreach (array_chunk($rows, 500) as $chunk) {
                $written += $this->db->table(self::TABLE)->insertOrIgnore($chunk);
            }

            return $written;
        });
    }

    public function welcomed(array $mentorIds): array
    {
        $zone = $this->time->storageTimezone();
        $welcomed = [];

        foreach (array_chunk(array_values(array_unique(array_map(strtolower(...), $mentorIds))), 500) as $chunk) {
            $rows = $this->db->table(self::TABLE.' as decision')
                // Anonymized or deleted for good: never counted, even before the listener erases them
                ->join('users', 'users.id', '=', 'decision.newcomer_id')
                ->whereNull('users.anonymized_at')
                ->whereIn('decision.mentor_id', $chunk)
                ->get(['decision.mentor_id', 'decision.decided_at']);

            foreach ($rows as $row) {
                /** @var stdClass $row */
                $welcomed[strtolower((string) $row->mentor_id)][] = new DateTimeImmutable(substr((string) $row->decided_at, 0, 19), $zone);
            }
        }

        foreach ($welcomed as $mentorId => $instants) {
            sort($instants);
            $welcomed[$mentorId] = $instants;
        }

        ksort($welcomed, SORT_STRING);

        return $welcomed;
    }

    public function forgetAccount(string $userId): void
    {
        $userId = strtolower($userId);

        $this->db->transaction(function () use ($userId): void {
            $this->db->table(self::TABLE)->where('newcomer_id', $userId)->delete();
            $this->db->table(self::TABLE)->where('mentor_id', $userId)->update(['mentor_id' => null]);
        });
    }

    /**
     * The accounts that exist and are not anonymized, read with a shared lock.
     *
     * @param  list<string>  $userIds
     * @return array<string, true>
     */
    private function liveAccounts(array $userIds): array
    {
        $live = [];

        foreach (array_chunk(array_values(array_unique(array_map(strtolower(...), $userIds))), 500) as $chunk) {
            foreach (UserModel::withTrashed()->whereIn('id', $chunk)->sharedLock()->get(['id', 'anonymized_at']) as $user) {
                if ($user->anonymized_at === null) {
                    $live[strtolower((string) $user->id)] = true;
                }
            }
        }

        return $live;
    }
}
