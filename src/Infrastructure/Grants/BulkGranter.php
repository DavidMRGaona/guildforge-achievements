<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Grants;

use App\Application\Activity\DTOs\ActivitySubjectDTO;
use App\Application\Activity\Services\ActivityReaderInterface;
use App\Domain\Activity\ActivityIdentifierRule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\DTOs\BulkGrantCountDTO;
use Modules\Achievements\Application\DTOs\BulkGrantRequestDTO;
use Modules\Achievements\Application\Enums\BulkGrantOutcome;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\BulkGrantInterface;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\NewGrant;

final readonly class BulkGranter implements BulkGrantInterface
{
    public function __construct(
        private ActivityReaderInterface $reader,
        private AchievementFactRepositoryInterface $facts,
        private AchievementRepositoryInterface $achievements,
        private AchievementGrantRepositoryInterface $grants,
        private AchievementGranterInterface $granter,
        private AchievementsSettingsInterface $settings,
        private ConnectionInterface $db,
    ) {}

    public function subjects(string $sourceType, ?string $search, Authenticatable $viewer): array
    {
        $ids = $this->facts->recentSubjectIds($sourceType, self::RECENT_SUBJECTS);

        if ($ids === []) {
            return [];
        }

        $described = $this->reader->describe($sourceType, $ids, $viewer);

        if (! $described->isRead()) {
            return [];
        }

        $search = $search === null ? '' : trim($search);
        $subjects = [];

        // In the ledger's order (latest first), whatever order the source answered in
        foreach ($ids as $id) {
            $subject = $described->find(ActivityIdentifierRule::stableId($id) ?? $id);

            if ($subject instanceof ActivitySubjectDTO && ($search === '' || mb_stripos($subject->title, $search) !== false)) {
                $subjects[] = $subject;
            }

            if (count($subjects) === self::MAX_SUBJECTS) {
                break;
            }
        }

        return $subjects;
    }

    public function subject(string $sourceType, string $subjectId, Authenticatable $viewer): ?ActivitySubjectDTO
    {
        $id = ActivityIdentifierRule::stableId($subjectId);

        if ($id === null) {
            return null;
        }

        $described = $this->reader->describe($sourceType, [$id], $viewer);

        return $described->isRead() ? $described->find($id) : null;
    }

    public function preview(BulkGrantRequestDTO $request, Authenticatable $viewer): BulkGrantCountDTO
    {
        $people = $this->participants($request, $viewer);

        if (! is_array($people)) {
            return BulkGrantCountDTO::refused($people);
        }

        if (count($people) > self::MAX_PEOPLE) {
            return new BulkGrantCountDTO(BulkGrantOutcome::TooMany, count($people));
        }

        [$held, $revoked] = $this->alreadyDecided($request->achievementId, $people);

        return new BulkGrantCountDTO(BulkGrantOutcome::Ready, count($people), count($held), count($revoked));
    }

    public function grant(BulkGrantRequestDTO $request, bool $silent, ?string $reason, Authenticatable $actor): BulkGrantCountDTO
    {
        $people = $this->participants($request, $actor);

        if (! is_array($people)) {
            return BulkGrantCountDTO::refused($people);
        }

        if (count($people) > self::MAX_PEOPLE) {
            return new BulkGrantCountDTO(BulkGrantOutcome::TooMany, count($people));
        }

        $actorId = (string) $actor->getAuthIdentifier();
        [$held, $revoked] = $this->alreadyDecided($request->achievementId, $people);
        $granted = 0;

        // A transaction per batch keeps each lock short; a batch that fails (a deadlock, say) is
        // rolled back alone and what earlier batches granted stays. Each notice waits for its commit.
        foreach (array_chunk(array_values(array_diff($people, $held, $revoked)), self::PER_BATCH) as $batch) {
            try {
                $granted += $this->db->transaction(fn (): int => $this->grantBatch($batch, $request, $silent, $reason, $actorId));
            } catch (QueryException|DeadlockException $exception) {
                // Neither the query nor its bindings: they would carry the accounts
                Log::warning('A bulk grant of an achievement stopped partway.', [
                    'achievement_id' => $request->achievementId,
                    'source_type' => $request->sourceType,
                    'granted' => $granted,
                    'exception' => $exception::class,
                ]);

                return new BulkGrantCountDTO(BulkGrantOutcome::Partial, count($people), count($held), count($revoked), $granted);
            }
        }

        return new BulkGrantCountDTO(BulkGrantOutcome::Granted, count($people), count($held), count($revoked), $granted);
    }

    /**
     * @param  list<string>  $batch
     */
    private function grantBatch(array $batch, BulkGrantRequestDTO $request, bool $silent, ?string $reason, string $actorId): int
    {
        $granted = 0;

        foreach ($batch as $userId) {
            $grant = $this->granter->grant(new NewGrant(
                userId: $userId,
                achievementId: $request->achievementId,
                source: GrantSource::Bulk,
                silent: $silent,
                actorId: $actorId,
                reason: $reason,
            ));

            if ($grant instanceof AchievementGrant) {
                $granted++;
            }
        }

        return $granted;
    }

    /**
     * The accounts (lower case, each once) that held the role, or why nothing can be granted.
     *
     * @return list<string>|BulkGrantOutcome
     */
    private function participants(BulkGrantRequestDTO $request, Authenticatable $viewer): array|BulkGrantOutcome
    {
        $settings = $this->settings->readStrictly();

        if (! $settings instanceof AchievementsSettings) {
            return BulkGrantOutcome::SettingsUnreadable;
        }

        if (! $settings->bulkGrants) {
            return BulkGrantOutcome::Disabled;
        }

        $achievement = $this->achievements->find($request->achievementId);

        if (! $achievement instanceof Achievement || ! $achievement->active) {
            return BulkGrantOutcome::AchievementUnavailable;
        }

        // Only from a subject whoever grants may see
        if (! $this->subject($request->sourceType, $request->subjectId, $viewer) instanceof ActivitySubjectDTO) {
            return BulkGrantOutcome::SubjectNotVisible;
        }

        $facts = $this->reader->facts($request->sourceType, $request->subjectId);

        if (! $facts->isRead()) {
            return BulkGrantOutcome::SourceUnavailable;
        }

        $people = [];

        foreach ($facts->facts as $fact) {
            if ($fact->role === $request->role) {
                $people[$fact->userId] = true;
            }
        }

        return array_keys($people);
    }

    /**
     * Of these accounts, who already holds the achievement and who had it taken away.
     *
     * @param  list<string>  $people
     * @return array{list<string>, list<string>}
     */
    private function alreadyDecided(string $achievementId, array $people): array
    {
        $achievementId = strtolower($achievementId);
        $held = [];

        foreach ($this->grants->heldAchievementIds($people) as $userId => $achievementIds) {
            if (in_array($achievementId, $achievementIds, true)) {
                $held[] = (string) $userId;
            }
        }

        $revoked = array_values(array_diff($this->grants->revokedAmong($achievementId, $people), $held));

        return [$held, $revoked];
    }
}
