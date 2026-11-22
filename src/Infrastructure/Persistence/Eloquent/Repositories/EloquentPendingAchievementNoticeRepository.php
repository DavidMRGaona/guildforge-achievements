<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Achievements\Domain\Repositories\PendingAchievementNoticeRepositoryInterface;

final readonly class EloquentPendingAchievementNoticeRepository implements PendingAchievementNoticeRepositoryInterface
{
    public const string TABLE = 'achievements_pending_notices';

    private const string GRANTS = 'achievements_grants';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(string $grantId): void
    {
        $grantId = strtolower($grantId);

        if (! AchievementIds::isWellFormed($grantId)) {
            return;
        }

        // Copied from the grant itself, in one statement: a grant revoked meanwhile adds nothing
        $this->db->table(self::TABLE)->insertOrIgnoreUsing(
            ['grant_id', 'user_id', 'granted_at'],
            $this->db->table(self::GRANTS)->where('id', $grantId)->select(['id', 'user_id', 'granted_at']),
        );
    }

    public function claim(string $userId): array
    {
        $userId = strtolower($userId);

        return $this->db->transaction(function () use ($userId): array {
            /** @var list<string> $grantIds */
            $grantIds = $this->table()
                ->where('user_id', $userId)
                ->orderBy('granted_at')
                ->orderBy('grant_id')
                ->lockForUpdate()
                ->pluck('grant_id')
                ->map(static fn (mixed $id): string => strtolower((string) $id))
                ->all();

            if ($grantIds !== []) {
                foreach (array_chunk($grantIds, 500) as $chunk) {
                    $this->table()->whereIn('grant_id', $chunk)->delete();
                }
            }

            return $grantIds;
        });
    }

    private function table(): Builder
    {
        return $this->db->table(self::TABLE);
    }
}
