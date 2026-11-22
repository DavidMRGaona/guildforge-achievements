<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Modules\Achievements\Application\DTOs\SourceRecalculationDTO;
use Modules\Achievements\Application\DTOs\SubjectRefreshDTO;

/**
 * Keeps the ledger in step with the activity sources (core#774), read only through the core's
 * ActivityReaderInterface. Only an answer replaces what is stored: a source that is missing or
 * failed leaves its rows as they were (the reader has already warned).
 *
 * Every kind is recorded, also one switched off in the settings: the switch only decides whether
 * it is evaluated, so turning it on again needs no recalculation of the ledger.
 *
 * Every refresh that read its source, and every history page, dispatches AchievementFactsChanged
 * with the accounts on either side, whether or not a row changed: the evaluator checks them again
 * (granting twice grants nothing).
 *
 * Reads and writes of one subject run under its lock, and what is stored is always what the
 * source's facts() said under that lock: a history page is only a hint of which subjects changed.
 */
interface ActivityLedgerInterface
{
    /** Rows per history page asked to a source */
    public const int HISTORY_PAGE_SIZE = 200;

    /** Pages of one source's history read at most in one walk (a million subjects) */
    public const int MAX_PAGES = 5000;

    /**
     * Reads the subject's facts again, under its lock, and stores them.
     */
    public function refreshSubject(string $sourceType, string $subjectId): SubjectRefreshDTO;

    /**
     * Walks the history of every source offered now (or of one), page by page. A subject whose
     * page differs from what is stored is read again with facts() and stored as that says. Once
     * the whole history was read, each stored subject it did not list is read again too: it goes
     * only when facts() is read with nothing (a subject that ended during the walk stays). A page,
     * or one of those reads, that is not read ends the source as failed without removing anything,
     * and so do a cursor seen before and more than MAX_PAGES pages. Everything is announced
     * silently; a dry run writes and announces nothing.
     *
     * @return list<SourceRecalculationDTO>
     */
    public function recalculate(?string $sourceType = null, bool $dryRun = false): array;

    /**
     * Deletes every fact of an anonymized account; returns how many there were.
     */
    public function forgetUser(string $userId): int;
}
