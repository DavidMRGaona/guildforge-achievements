<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Modules\Achievements\Domain\Repositories\MentorDecisionRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\MentorDecision;
use stdClass;

/**
 * Written with the query builder, so instants are formatted in the storage zone (PHP's default zone)
 * by hand, like the ledger.
 */
final readonly class EloquentMentorDecisionRepository implements MentorDecisionRepositoryInterface
{
    public const string TABLE = 'achievements_mentor_decisions';

    private const string STORAGE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private ConnectionInterface $db,
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
        $now = new DateTimeImmutable('now', $this->zone())->format(self::STORAGE_FORMAT);
        $rows = array_map(fn (MentorDecision $decision): array => [
            'newcomer_id' => strtolower($decision->newcomerId),
            'mentor_id' => $decision->mentorId === null ? null : strtolower($decision->mentorId),
            'decided_at' => $decision->decidedAt->setTimezone($this->zone())->format(self::STORAGE_FORMAT),
            'created_at' => $now,
            'updated_at' => $now,
        ], $decisions);
        $written = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $written += $this->db->table(self::TABLE)->insertOrIgnore($chunk);
        }

        return $written;
    }

    public function welcomed(array $mentorIds): array
    {
        $zone = $this->zone();
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

    private function zone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }
}
