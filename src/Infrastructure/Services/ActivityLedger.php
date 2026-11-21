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
    ) {}

    public function refreshSubject(string $sourceType, string $subjectId): ?FactsReplacement
    {
        $subjectId = ActivityIdentifierRule::stableId($subjectId);

        if ($subjectId === null) {
            return null;
        }

        $result = $this->reader->facts($sourceType, $subjectId);

        // Missing or failed: unknown, so what is stored stays
        if (! $result->isRead()) {
            return null;
        }

        $kind = $this->kindOf($sourceType);

        if ($kind === null) {
            return null;
        }

        $replacement = $this->facts->replaceSubject($sourceType, $subjectId, $kind, $this->recorded($result->facts));

        // Announced even when nothing changed: the evaluator may have skipped these people before
        // (settings it could not read), and granting twice grants nothing
        $this->announce($replacement->affectedUserIds(), silent: false);

        return $replacement;
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

    private function recalculateSource(string $type, bool $dryRun): SourceRecalculationDTO
    {
        $kind = $this->kindOf($type);
        /** @var array<string, true> $seenCursors */
        $seenCursors = [];
        /** @var array<array-key, true> $seenSubjects numeric ids turn into int keys */
        $seenSubjects = [];
        $changed = 0;
        $cursor = null;

        while (true) {
            $result = $this->reader->history($type, $cursor, self::HISTORY_PAGE_SIZE);
            $page = $result->page;

            if (! $result->isRead() || ! $page instanceof ActivityHistoryPageDTO || $kind === null) {
                $outcome = $result->outcome === ActivityReadOutcome::Missing ? RecalculationOutcome::Missing : RecalculationOutcome::Failed;

                return new SourceRecalculationDTO($type, $outcome, count($seenSubjects), $changed);
            }

            $affected = [];

            foreach ($page->subjects as $subject) {
                $seenSubjects[$subject->subjectId] = true;
                $replacement = $this->store($type, $subject->subjectId, $kind, $this->recorded($subject->facts), $dryRun);
                $changed += $replacement->changed ? 1 : 0;
                array_push($affected, ...$replacement->affectedUserIds());
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

        // The whole history was read: a stored subject it does not list no longer counts
        $removed = 0;
        $affected = [];

        foreach ($this->facts->subjectIds($type) as $storedId) {
            if (isset($seenSubjects[$storedId])) {
                continue;
            }

            $replacement = $this->store($type, $storedId, $kind, [], $dryRun);
            $removed++;
            array_push($affected, ...$replacement->affectedUserIds());
        }

        $this->announceQuietly($affected, $dryRun);

        return new SourceRecalculationDTO($type, RecalculationOutcome::Complete, count($seenSubjects), $changed, $removed);
    }

    /**
     * @param  list<RecordedFact>  $facts
     */
    private function store(string $type, string $subjectId, string $kind, array $facts, bool $dryRun): FactsReplacement
    {
        return $dryRun
            ? $this->facts->previewReplacement($type, $subjectId, $kind, $facts)
            : $this->facts->replaceSubject($type, $subjectId, $kind, $facts);
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
