<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Recognitions;

use App\Application\Activity\DTOs\ActivityFactDTO;
use App\Application\Activity\DTOs\ActivitySubjectDTO;
use App\Application\Activity\Services\ActivityReaderInterface;
use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Date;
use Modules\Achievements\Application\DTOs\OwnRecognitionDTO;
use Modules\Achievements\Application\DTOs\RecognisablePersonDTO;
use Modules\Achievements\Application\DTOs\RecognitionSubjectDTO;
use Modules\Achievements\Application\DTOs\RecognitionTotalDTO;
use Modules\Achievements\Application\DTOs\RecognitionTypeViewDTO;
use Modules\Achievements\Application\Enums\GiveRecognitionOutcome;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\RecognitionsInterface;
use Modules\Achievements\Domain\Entities\AchievementFact;
use Modules\Achievements\Domain\Recognitions\Recognition;
use Modules\Achievements\Domain\Recognitions\RecognitionCut;
use Modules\Achievements\Domain\Recognitions\RecognitionRefusal;
use Modules\Achievements\Domain\Recognitions\RecognitionType;
use Modules\Achievements\Domain\Recognitions\RecognitionWindow;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Repositories\RecognitionRepositoryInterface;
use Modules\Achievements\Jobs\NotifyRecognitionReceivedJob;

/**
 * Scoped: the types are read once per request or job.
 *
 * The ledger only says which sessions and campaigns the giver may have taken part in lately;
 * who took part is always read again from the source through the core's reader (only a Read answer
 * counts) and the title through describe() for the giver, so nothing stale or hidden is offered.
 */
final class Recognitions implements RecognitionsInterface
{
    /** @var array<string, RecognitionType>|null */
    private ?array $types = null;

    public function __construct(
        private readonly AchievementsSettingsInterface $settings,
        private readonly RecognitionRepositoryInterface $recognitions,
        private readonly AchievementFactRepositoryInterface $facts,
        private readonly ActivityReaderInterface $reader,
        private readonly RecognisablePeople $people,
        private readonly TimezoneServiceInterface $time,
        private readonly RecognitionNotices $notices,
    ) {}

    public function available(): bool
    {
        return $this->settings->recognitionsEnabled() && $this->activeTypes() !== [];
    }

    public function openSubjects(Authenticatable $giver): array
    {
        if (! $this->available()) {
            return [];
        }

        $giverId = strtolower((string) $giver->getAuthIdentifier());
        $given = $this->givenIn($giverId);
        $subjects = [];

        foreach ($this->openFacts($giverId) as $fact) {
            $participants = $this->participants($fact->sourceType, $fact->subjectId, $giverId);

            if ($participants === null) {
                continue;
            }

            $description = $this->describe($fact->sourceType, $fact->subjectId, $giver);

            if (! $description instanceof ActivitySubjectDTO) {
                continue;
            }

            $people = [];

            foreach ($this->people->among($participants['others'], $giver) as $userId => $person) {
                $people[] = new RecognisablePersonDTO(
                    handle: $person['handle'],
                    publicName: $person['publicName'],
                    profileUrl: $person['profileUrl'],
                    avatarUrl: $person['avatarUrl'],
                    givenTypeId: $given[$fact->sourceType."\0".$fact->subjectId."\0".$userId] ?? null,
                );
            }

            if ($people === []) {
                continue;
            }

            usort($people, static fn (RecognisablePersonDTO $a, RecognisablePersonDTO $b): int => [mb_strtolower($a->publicName), $a->handle] <=> [mb_strtolower($b->publicName), $b->handle]);

            $subjects[] = new RecognitionSubjectDTO(
                sourceType: $fact->sourceType,
                subjectId: $fact->subjectId,
                title: $description->title,
                url: $description->url,
                closesOn: $this->closesOn($participants['occurredAt']),
                people: $people,
            );
        }

        return $subjects;
    }

    public function give(Authenticatable $giver, string $sourceType, string $subjectId, string $receiverHandle, string $typeId): GiveRecognitionOutcome
    {
        $typeId = strtolower($typeId);

        if (! $this->settings->recognitionsEnabled() || ! isset($this->activeTypes()[$typeId])) {
            return GiveRecognitionOutcome::Refused;
        }

        $giverId = strtolower((string) $giver->getAuthIdentifier());
        $fact = array_find($this->openFacts($giverId), fn ($open) => $open->sourceType === $sourceType && $open->subjectId === $subjectId);

        if (! $fact instanceof AchievementFact) {
            return GiveRecognitionOutcome::Refused;
        }

        $kind = $fact->kind;

        // Read and stored under the subject's lock, as the ledger refreshes it: a refresh that takes
        // either person off the subject waits for this one and then deletes it, never the other way
        $outcome = $this->facts->withSubjectLock($sourceType, $subjectId, function () use ($sourceType, $subjectId, $giverId, $giver, $receiverHandle, $typeId, $kind): GiveRecognitionOutcome|Recognition {
            $participants = $this->participants($sourceType, $subjectId, $giverId);

            if ($participants === null || ! $this->describe($sourceType, $subjectId, $giver) instanceof ActivitySubjectDTO) {
                return GiveRecognitionOutcome::Refused;
            }

            $receiverId = null;

            foreach ($this->people->among($participants['others'], $giver) as $userId => $person) {
                if ($person['handle'] === mb_strtolower(trim($receiverHandle))) {
                    $receiverId = (string) $userId;
                }
            }

            if ($receiverId === null) {
                return GiveRecognitionOutcome::Refused;
            }

            return match ($given = $this->recognitions->give($typeId, $giverId, $receiverId, $sourceType, $subjectId, $kind, $this->now())) {
                RecognitionRefusal::AlreadyGiven => GiveRecognitionOutcome::AlreadyGiven,
                // An account anonymized meanwhile: the same answer as anybody who cannot be chosen
                RecognitionRefusal::AccountGone => GiveRecognitionOutcome::Refused,
                default => $given,
            };
        });

        if (! $outcome instanceof Recognition) {
            return $outcome;
        }

        NotifyRecognitionReceivedJob::dispatch($outcome->id);

        return GiveRecognitionOutcome::Given;
    }

    public function received(Authenticatable $owner): array
    {
        $ownerId = strtolower((string) $owner->getAuthIdentifier());

        return $this->own($this->recognitions->receivedBy($ownerId), static fn (Recognition $one): string => $one->giverId, $owner);
    }

    public function given(Authenticatable $owner): array
    {
        $ownerId = strtolower((string) $owner->getAuthIdentifier());

        return $this->own($this->recognitions->givenBy($ownerId), static fn (Recognition $one): string => $one->receiverId, $owner);
    }

    public function deleteReceived(Authenticatable $owner, string $recognitionId): bool
    {
        $recognition = $this->recognitions->find($recognitionId);

        if (! $recognition instanceof Recognition || $recognition->receiverId !== strtolower((string) $owner->getAuthIdentifier()) || ! $this->recognitions->delete($recognition->id)) {
            return false;
        }

        $this->notices->forget([$recognition->id]);

        return true;
    }

    public function totals(string $ownerId, ?string $viewerId): array
    {
        $ownerId = strtolower($ownerId);
        $isOwner = $viewerId !== null && strtolower($viewerId) === $ownerId;
        $before = $isOwner ? null : RecognitionCut::at($this->now(), $this->time->displayTimezone());
        $counts = $this->recognitions->totals([$ownerId], $before)[$ownerId] ?? [];
        $types = $this->types();
        $totals = [];

        foreach ($counts as $typeId => $count) {
            if ($count > 0 && isset($types[$typeId])) {
                $totals[] = new RecognitionTotalDTO(RecognitionTypeViewDTO::of($types[$typeId]), $count);
            }
        }

        usort($totals, static fn (RecognitionTotalDTO $a, RecognitionTotalDTO $b): int => [-$a->count, mb_strtolower($a->type->name), $a->type->id]
            <=> [-$b->count, mb_strtolower($b->type->name), $b->type->id]);

        return $totals;
    }

    /**
     * The giver's sessions and campaigns of the ledger still within the window, one per subject,
     * the most recent first.
     *
     * @return list<AchievementFact>
     */
    private function openFacts(string $giverId): array
    {
        $now = $this->now();
        $zone = $this->time->displayTimezone();
        $days = $this->settings->recognitionsWindowDays();
        $open = [];

        foreach ($this->facts->forUser($giverId, RecognitionWindow::KINDS) as $fact) {
            if (RecognitionWindow::recognisesKind($fact->kind) && RecognitionWindow::isOpen($fact->occurredAt, $now, $zone, $days)) {
                $open[$fact->sourceType."\0".$fact->subjectId] ??= $fact;
            }
        }

        $open = array_values($open);
        usort($open, static fn (AchievementFact $a, AchievementFact $b): int => $b->occurredAt <=> $a->occurredAt);

        return $open;
    }

    /**
     * Who took part in the subject as its source says now, without the giver; null unless the
     * answer is Read, the giver is among them and the subject is still within the window by the
     * giver's own fact.
     *
     * @return array{others: list<string>, occurredAt: DateTimeImmutable}|null
     */
    private function participants(string $sourceType, string $subjectId, string $giverId): ?array
    {
        $result = $this->reader->facts($sourceType, $subjectId);

        if (! $result->isRead()) {
            return null;
        }

        $others = [];
        $occurredAt = null;

        foreach ($result->facts as $fact) {
            /** @var ActivityFactDTO $fact */
            $userId = strtolower($fact->userId);

            if ($userId === $giverId) {
                $occurredAt = $occurredAt === null || $fact->occurredAt > $occurredAt ? $fact->occurredAt : $occurredAt;
            } else {
                $others[$userId] = true;
            }
        }

        $days = $this->settings->recognitionsWindowDays();

        if (! $occurredAt instanceof DateTimeImmutable || ! RecognitionWindow::isOpen($occurredAt, $this->now(), $this->time->displayTimezone(), $days)) {
            return null;
        }

        return ['others' => array_map(strval(...), array_keys($others)), 'occurredAt' => $occurredAt];
    }

    private function describe(string $sourceType, string $subjectId, Authenticatable $giver): ?ActivitySubjectDTO
    {
        $result = $this->reader->describe($sourceType, [$subjectId], $giver);

        return $result->isRead() ? $result->find($subjectId) : null;
    }

    /**
     * What the giver already gave: subject and receiver => type id.
     *
     * @return array<string, string>
     */
    private function givenIn(string $giverId): array
    {
        $given = [];

        foreach ($this->recognitions->givenBy($giverId) as $recognition) {
            $given[$recognition->sourceType."\0".$recognition->subjectId."\0".$recognition->receiverId] = $recognition->typeId;
        }

        return $given;
    }

    /**
     * @param  list<Recognition>  $recognitions
     * @param  callable(Recognition): string  $other
     * @return list<OwnRecognitionDTO>
     */
    private function own(array $recognitions, callable $other, Authenticatable $owner): array
    {
        $types = $this->types();
        $visible = $this->people->named(array_values(array_unique(array_map($other, $recognitions))), $owner);
        $own = [];

        foreach ($recognitions as $recognition) {
            $type = $types[$recognition->typeId] ?? null;

            if (! $type instanceof RecognitionType) {
                continue;
            }

            $person = $visible[$other($recognition)] ?? null;

            $own[] = new OwnRecognitionDTO(
                id: $recognition->id,
                type: RecognitionTypeViewDTO::of($type),
                personName: $person['publicName'] ?? null,
                personUrl: $person['profileUrl'] ?? null,
                month: $this->time->toDisplay($recognition->givenAt)->format('Y-m'),
            );
        }

        return $own;
    }

    private function closesOn(DateTimeImmutable $occurredAt): string
    {
        $zone = $this->time->displayTimezone();

        return RecognitionWindow::closesAt($occurredAt, $zone, $this->settings->recognitionsWindowDays())->modify('-1 day')->format('Y-m-d');
    }

    /**
     * @return array<string, RecognitionType>
     */
    private function types(): array
    {
        return $this->types ??= $this->recognitions->types();
    }

    /**
     * @return array<string, RecognitionType>
     */
    private function activeTypes(): array
    {
        return array_filter($this->types(), static fn (RecognitionType $type): bool => $type->active);
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(Date::now());
    }
}
