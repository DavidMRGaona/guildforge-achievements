<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

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

    public function claim(string $userId): array
    {
        $claimed = $this->take(self::EARNED, strtolower($userId));

        return $claimed[strtolower($userId)] ?? [];
    }

    public function claimAnnouncements(): array
    {
        return $this->take(self::ANNOUNCEMENT, null);
    }

    /**
     * @return array<string, list<string>> holder id => grant ids, oldest first
     */
    private function take(string $kind, ?string $userId): array
    {
        return $this->db->transaction(function () use ($kind, $userId): array {
            $rows = $this->table()
                ->where('kind', $kind)
                ->when($userId !== null, static fn (Builder $query): Builder => $query->where('user_id', $userId))
                ->orderBy('granted_at')
                ->orderBy('grant_id')
                ->lockForUpdate()
                ->get(['grant_id', 'user_id']);

            $claimed = [];
            $grantIds = [];

            foreach ($rows as $row) {
                $grantId = strtolower((string) $row->grant_id);
                $claimed[strtolower((string) $row->user_id)][] = $grantId;
                $grantIds[] = $grantId;
            }

            foreach (array_chunk($grantIds, self::CHUNK) as $chunk) {
                $this->table()->where('kind', $kind)->whereIn('grant_id', $chunk)->delete();
            }

            return $claimed;
        });
    }

    private function table(): Builder
    {
        return $this->db->table(self::TABLE);
    }
}
