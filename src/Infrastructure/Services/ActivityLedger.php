<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Services;

use App\Application\Activity\DTOs\ActivityAttributeValueDTO;
use App\Application\Activity\DTOs\ActivityFactDTO;
use App\Application\Activity\DTOs\ActivityHistoryPageDTO;
use App\Application\Activity\Enums\ActivityReadOutcome;
use App\Application\Activity\Services\ActivityReaderInterface;
use App\Domain\Activity\ActivityIdentifierRule;
use Illuminate\Contracts\Events\Dispatcher;
use Modules\Achievements\Application\DTOs\SourceRecalculationDTO;
use Modules\Achievements\Application\DTOs\SubjectRefreshDTO;
use Modules\Achievements\Application\Enums\RecalculationOutcome;
use Modules\Achievements\Application\Services\ActivityLedgerInterface;
use Modules\Achievements\Domain\Events\AchievementFactsChanged;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\FactAttribute;
use Modules\Achievements\Domain\ValueObjects\FactsReplacement;
use Modules\Achievements\Domain\ValueObjects\RecordedFact;

/**
 * The settings are not read here on purpose: a kind switched off is still recorded, and only the
 * rule evaluator skips it.
 */
final class ActivityLedger implements ActivityLedgerInterface
{
    /** @var array<string, string>|null source type => kind, as the reader offers them now */
    private ?array $kinds = null;

    public function __construct(
        private readonly ActivityReaderInterface $reader,
        private readonly AchievementFactRepositoryInterface $facts,
        private readonly Dispatcher $events,
        private readonly int $maxPages = self::MAX_PAGES,
    ) {}

    public function refreshSubject(string $sourceType, string $subjectId): SubjectRefreshDTO
    {
        $subjectId = ActivityIdentifierRule::stableId($subjectId);

        // The reader would read it with no facts too, and no stored subject has such an id
        if ($subjectId === null) {
            return new SubjectRefreshDTO(ActivityReadOutcome::Read);
        }

        $refresh = $this->refresh($sourceType, $subjectId, dryRun: false);

        if ($refresh->replacement instanceof FactsReplacement) {
            $this->announce($refresh->replacement->affectedUserIds(), silent: false);
        }

        return $refresh;
    }

    public function recalculate(?string $sourceType = null, bool $dryRun = false): array
    {
        $types = $sourceType !== null
            ? [$sourceType]
            : array_keys($this->kinds());

        return array_map(fn (string $type): SourceRecalculationDTO => $this->recalculateSource($type, $dryRun), $types);
    }

    public function forgetUser(string $userId): int
    {
        return $this->facts->deleteForUser($userId);
    }

    /**
     * Reads the subject's facts and stores them, both under its lock (a dry run only previews,
     * without the lock, which would write its row). Missing and failed reads change nothing.
     */
    private function refresh(string $type, string $subjectId, bool $dryRun): SubjectRefreshDTO
    {
        $work = function () use ($type, $subjectId, $dryRun): SubjectRefreshDTO {
            $result = $this->reader->facts($type, $subjectId);

            // Missing or failed: unknown, so what is stored stays (the reader has already warned)
            if (! $result->isRead()) {
                return new SubjectRefreshDTO($result->outcome);
            }

            $kind = $this->kindOf($type);

            // Read, yet not among the sources offered now (its label failed): not a full answer
            if ($kind === null) {
                return new SubjectRefreshDTO(ActivityReadOutcome::Failed);
            }

            $facts = $this->recorded($result->facts);
            $replacement = $dryRun
                ? $this->facts->previewReplacement($type, $subjectId, $kind, $facts)
                : $this->facts->replaceSubject($type, $subjectId, $kind, $facts);

            return new SubjectRefreshDTO(ActivityReadOutcome::Read, $replacement);
        };

        return $dryRun ? $work() : $this->facts->withSubjectLock($type, $subjectId, $work);
    }

    private function recalculateSource(string $type, bool $dryRun): SourceRecalculationDTO
    {
        $kind = $this->kindOf($type);
        /** @var array<string, true> $seenCursors */
        $seenCursors = [];
        /** @var array<array-key, true> $seenSubjects numeric ids turn into int keys */
        $seenSubjects = [];
        $changed = 0;
        $cursor = null;
        $pages = 0;

        while (true) {
            if (++$pages > $this->maxPages) {
                return new SourceRecalculationDTO($type, RecalculationOutcome::Failed, count($seenSubjects), $changed);
            }

            $result = $this->reader->history($type, $cursor, self::HISTORY_PAGE_SIZE);
            $page = $result->page;

            if (! $result->isRead() || ! $page instanceof ActivityHistoryPageDTO || $kind === null) {
                return new SourceRecalculationDTO($type, $result->outcome === ActivityReadOutcome::Missing ? RecalculationOutcome::Missing : RecalculationOutcome::Failed, count($seenSubjects), $changed);
            }

            $affected = [];

            foreach ($page->subjects as $subject) {
                $seenSubjects[$subject->subjectId] = true;

                // The page may be older than what is stored (a refresh ran meanwhile): it only says
                // whether to look, and facts() under the lock says what is true
                $hint = $this->facts->previewReplacement($type, $subject->subjectId, $kind, $this->recorded($subject->facts));

                if (! $hint->changed) {
                    array_push($affected, ...$hint->affectedUserIds());

                    continue;
                }

                $refresh = $this->refresh($type, $subject->subjectId, $dryRun);

                if (! $refresh->replacement instanceof FactsReplacement) {
                    $this->announceQuietly($affected, $dryRun);

                    return new SourceRecalculationDTO($type, RecalculationOutcome::Failed, count($seenSubjects), $changed);
                }

                $changed += $refresh->replacement->changed ? 1 : 0;
                array_push($affected, ...$refresh->replacement->affectedUserIds());
            }

            $this->announceQuietly($affected, $dryRun);
            $cursor = $page->nextCursor;

            if ($cursor === null) {
                break;
            }

            // A broken source could send the walk round two pages forever
            if (isset($seenCursors[$cursor])) {
                return new SourceRecalculationDTO($type, RecalculationOutcome::Cycle, count($seenSubjects), $changed);
            }

            $seenCursors[$cursor] = true;
        }

        // The whole history was read. A stored subject it did not list may have started counting
        // after its page went by, so facts() decides: it goes only when read with nothing
        $removed = 0;
        $affected = [];

        foreach ($this->facts->subjectIds($type) as $storedId) {
            if (isset($seenSubjects[$storedId])) {
                continue;
            }

            $refresh = $this->refresh($type, $storedId, $dryRun);

            if (! $refresh->replacement instanceof FactsReplacement) {
                $this->announceQuietly($affected, $dryRun);

                return new SourceRecalculationDTO($type, RecalculationOutcome::Failed, count($seenSubjects), $changed, $removed);
            }

            if ($refresh->replacement->userIdsAfter === []) {
                $removed++;
            } elseif ($refresh->replacement->changed) {
                $changed++;
            }

            array_push($affected, ...$refresh->replacement->affectedUserIds());
        }

        $this->announceQuietly($affected, $dryRun);

        return new SourceRecalculationDTO($type, RecalculationOutcome::Complete, count($seenSubjects), $changed, $removed);
    }

    /**
     * @param  list<string>  $userIds
     */
    private function announceQuietly(array $userIds, bool $dryRun): void
    {
        if (! $dryRun) {
            $this->announce($userIds, silent: true);
        }
    }

    /**
     * @param  list<string>  $userIds
     */
    private function announce(array $userIds, bool $silent): void
    {
        $userIds = array_values(array_unique($userIds));

        if ($userIds === []) {
            return;
        }

        sort($userIds, SORT_STRING);
        $this->events->dispatch(new AchievementFactsChanged($userIds, $silent));
    }

    /**
     * @param  list<ActivityFactDTO>  $facts
     * @return list<RecordedFact>
     */
    private function recorded(array $facts): array
    {
        return array_map(static fn (ActivityFactDTO $fact): RecordedFact => new RecordedFact(
            userId: $fact->userId,
            role: $fact->role->value,
            occurredAt: $fact->occurredAt,
            attributes: array_map(
                static fn (ActivityAttributeValueDTO $value): FactAttribute => new FactAttribute($value->id, $value->label),
                $fact->attributes,
            ),
            placement: $fact->placement,
        ), $facts);
    }

    private function kindOf(string $type): ?string
    {
        return $this->kinds()[$type] ?? null;
    }

    /**
     * Read once per instance (a queued job or a command run builds its own).
     *
     * @return array<string, string>
     */
    private function kinds(): array
    {
        if ($this->kinds === null) {
            $this->kinds = [];

            foreach ($this->reader->sources() as $source) {
                $this->kinds[$source->type] = $source->kind->value;
            }
        }

        return $this->kinds;
    }
}
