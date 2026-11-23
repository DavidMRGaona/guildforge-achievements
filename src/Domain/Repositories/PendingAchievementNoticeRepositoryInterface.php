<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

use Closure;
use DateTimeInterface;

/**
 * Grants waiting to be told (`achievements_pending_notices`): once to the holder, together with the
 * rest of theirs, and once in the monthly summary of announcements. A revoked grant leaves the queue
 * with its row.
 *
 * Each delivery holds what it tells locked while it tells it and deletes it only once the work given
 * returned: work that throws leaves everything waiting for a retry, and a concurrent delivery waits
 * and then finds nothing.
 */
interface PendingAchievementNoticeRepositoryInterface
{
    /**
     * Queues both notices of a grant. A grant already queued, or gone meanwhile (revoked, its account
     * anonymized), changes nothing.
     */
    public function add(string $grantId): void;

    /**
     * Hands every grant waiting to be told to this person (oldest first) to the work, then forgets
     * them, in one transaction. Nothing waiting: the work is not called.
     *
     * @param  Closure(list<string>): void  $work  receives grant ids
     */
    public function deliverEarned(string $userId, Closure $work): void;

    /**
     * Hands every grant got before the instant and waiting to be announced, keyed by holder in lower
     * case, to the work, then forgets them, in one transaction. Nothing waiting: the work is not called.
     *
     * @param  Closure(array<string, list<string>>): void  $work  receives holder id => grant ids
     */
    public function releaseAnnouncements(DateTimeInterface $before, Closure $work): void;
}
