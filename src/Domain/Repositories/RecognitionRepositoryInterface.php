<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Repositories;

use DateTimeImmutable;
use Modules\Achievements\Domain\Recognitions\Recognition;
use Modules\Achievements\Domain\Recognitions\RecognitionType;

/**
 * Recognitions (#11) and their types. Account ids are read and written in lower case.
 */
interface RecognitionRepositoryInterface
{
    /**
     * Stores one; null when the giver already recognised that person for that subject (also when a
     * concurrent request won), or when either account is anonymized (read under a shared lock).
     */
    public function give(string $typeId, string $giverId, string $receiverId, string $sourceType, string $subjectId, string $kind, DateTimeImmutable $givenAt): ?Recognition;

    public function find(string $id): ?Recognition;

    public function delete(string $id): bool;

    /**
     * @return list<Recognition> newest first
     */
    public function receivedBy(string $userId): array;

    /**
     * @return list<Recognition> newest first
     */
    public function givenBy(string $userId): array;

    /**
     * How many each account received of each type, given strictly before `$before` (null: all).
     *
     * @param  list<string>  $userIds
     * @return array<string, array<string, int>> account => type id => count
     */
    public function totals(array $userIds, ?DateTimeImmutable $before): array;

    /**
     * When each recognition counted for each account, given strictly before `$before`, of one type
     * or any (the instants an achievement rule counts).
     *
     * @param  list<string>  $userIds
     * @return array<string, list<DateTimeImmutable>>
     */
    public function receivedInstants(array $userIds, ?string $typeId, DateTimeImmutable $before): array;

    /**
     * Accounts that received something before `$before`, in id order after `$afterUserId`.
     *
     * @return list<string>
     */
    public function receiverIds(DateTimeImmutable $before, ?string $afterUserId, int $limit): array;

    /**
     * Deletes the recognitions of a subject given or received by somebody not among `$userIds` (the
     * people with a fact in it now). Returns how many went.
     *
     * @param  list<string>  $userIds
     */
    public function keepOnlyParticipants(string $sourceType, string $subjectId, array $userIds): int;

    /**
     * Every recognition the account gave or received, and its prompts.
     */
    public function deleteForUser(string $userId): int;

    /**
     * @param  list<array{string, string}>  $subjects  [source type, subject id]
     * @return list<array{string, string}> those the account was already asked about
     */
    public function prompted(string $userId, array $subjects): array;

    /**
     * Remembers that the account was asked about these subjects (once each, whatever runs twice).
     *
     * @param  list<array{string, string}>  $subjects
     */
    public function markPrompted(string $userId, array $subjects, DateTimeImmutable $at): void;

    /**
     * Every type, active or not, by name.
     *
     * @return array<string, RecognitionType> id => type
     */
    public function types(): array;

    public function typeInUse(string $typeId): bool;
}
