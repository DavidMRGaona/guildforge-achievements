<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Modules\Achievements\Application\DTOs\SourceRecalculationDTO;
use Modules\Achievements\Domain\ValueObjects\FactsReplacement;

/**
 * Keeps the ledger in step with the activity sources (core#774), read only through the core's
 * ActivityReaderInterface. Only an answer replaces what is stored: a source that is missing or
 * failed leaves its rows as they were (the reader has already warned).
 *
 * Every kind is recorded, also one switched off in the settings: the switch only decides whether
 * it is evaluated, so turning it on again needs no recalculation of the ledger.
 *
 * After each change it dispatches AchievementFactsChanged with the accounts on either side.
 */
interface ActivityLedgerInterface
{
    /** Rows per history page asked to a source */
    public const int HISTORY_PAGE_SIZE = 200;

    /**
     * Reads the subject's facts again and stores them. Null when nothing was stored: the source is
     * missing or failed, or the id cannot be a subject's.
     */
    public function refreshSubject(string $sourceType, string $subjectId): ?FactsReplacement;

    /**
     * Walks the history of every source offered now (or of one) and stores each subject's facts.
     * A stored subject absent from a history read completely is removed. Nothing is announced
     * but silently; a dry run writes and announces nothing.
     *
     * @return list<SourceRecalculationDTO>
     */
    public function recalculate(?string $sourceType = null, bool $dryRun = false): array;

    /**
     * Deletes every fact of an anonymized account; returns how many there were.
     */
    public function forgetUser(string $userId): int;
}
