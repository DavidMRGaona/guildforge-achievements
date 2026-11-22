<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use Closure;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Achievements\Domain\Repositories\PendingAchievementNoticeRepositoryInterface;

final readonly class EloquentPendingAchievementNoticeRepository implements PendingAchievementNoticeRepositoryInterface
{
    public const string TABLE = 'achievements_pending_notices';

    public const string EARNED = 'earned';

    public const string ANNOUNCEMENT = 'announcement';

    private const string GRANTS = 'achievements_grants';

    private const int CHUNK = 500;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(string $grantId): void
    {
        $grantId = strtolower($grantId);

        if (! AchievementIds::isWellFormed($grantId)) {
            return;
        }

        foreach ([self::EARNED, self::ANNOUNCEMENT] as $kind) {
            // Copied from the grant itself, in one statement: a grant revoked meanwhile adds nothing
            $this->db->table(self::TABLE)->insertOrIgnoreUsing(
                ['grant_id', 'kind', 'user_id', 'granted_at'],
                $this->db->table(self::GRANTS)
                    ->where('id', $grantId)
                    ->select('id')
                    ->selectRaw('? as kind', [$kind])
                    ->addSelect(['user_id', 'granted_at']),
            );
        }
    }

    public function deliverEarned(string $userId, Closure $work): void
    {
        $userId = strtolower($userId);

        $this->deliver(
            self::EARNED,
            static fn (Builder $query): Builder => $query->where('user_id', $userId),
            static function (array $byHolder) use ($work, $userId): void {
                $work($byHolder[$userId] ?? []);
            },
        );
    }

    public function releaseAnnouncements(DateTimeInterface $before, Closure $work): void
    {
        $this->deliver(
            self::ANNOUNCEMENT,
            static fn (Builder $query): Builder => $query->where('granted_at', '<', $before->format('Y-m-d H:i:s')),
            $work,
        );
    }

    /**
     * @param  Closure(Builder): Builder  $scope
     * @param  Closure(array<string, list<string>>): void  $work
     */
    private function deliver(string $kind, Closure $scope, Closure $work): void
    {
        $this->db->transaction(function () use ($kind, $scope, $work): void {
            $rows = $scope($this->table()->where('kind', $kind))
                ->orderBy('granted_at')
                ->orderBy('grant_id')
                ->lockForUpdate()
                ->get(['grant_id', 'user_id']);

            if ($rows->isEmpty()) {
                return;
            }

            $byHolder = [];
            $grantIds = [];

            foreach ($rows as $row) {
                $grantId = strtolower((string) $row->grant_id);
                $byHolder[strtolower((string) $row->user_id)][] = $grantId;
                $grantIds[] = $grantId;
            }

            // Told first: work that throws leaves them all waiting
            $work($byHolder);

            foreach (array_chunk($grantIds, self::CHUNK) as $chunk) {
                $this->table()->where('kind', $kind)->whereIn('grant_id', $chunk)->delete();
            }
        });
    }

    private function table(): Builder
    {
        return $this->db->table(self::TABLE);
    }
}
