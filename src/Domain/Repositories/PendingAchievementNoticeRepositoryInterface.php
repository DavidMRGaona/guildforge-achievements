<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

/**
 * Grants waiting to be told (`achievements_pending_notices`): once to the holder, together with the
 * rest of theirs, and once in the daily release of announcements. A revoked grant leaves the queue
 * with its row.
 */
interface PendingAchievementNoticeRepositoryInterface
{
    /**
     * Queues both notices of a grant. A grant already queued, or gone meanwhile (revoked, its account
     * anonymized), changes nothing.
     */
    public function add(string $grantId): void;

    /**
     * Takes every grant waiting to be told to this person, oldest first, and leaves none: a second
     * claim, even a concurrent one, gets nothing of what the first took.
     *
     * @return list<string> grant ids
     */
    public function claim(string $userId): array;

    /**
     * Takes every grant waiting to be announced, oldest first, keyed by holder in lower case, and
     * leaves none.
     *
     * @return array<string, list<string>> holder id => grant ids
     */
    public function claimAnnouncements(): array;
}
