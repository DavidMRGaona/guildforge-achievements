<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

/**
 * Grants waiting to be told (`achievements_pending_notices`): each one is told once, together with
 * the rest of the same person's, and a revoked grant leaves the queue with its row.
 */
interface PendingAchievementNoticeRepositoryInterface
{
    /**
     * Queues the notice of a grant for its holder. A grant already queued, or gone meanwhile (revoked,
     * its account anonymized), changes nothing.
     */
    public function add(string $grantId): void;

    /**
     * Takes every grant waiting for this person, oldest first, and leaves none: a second claim, even a
     * concurrent one, gets nothing of what the first took.
     *
     * @return list<string> grant ids
     */
    public function claim(string $userId): array;
}
