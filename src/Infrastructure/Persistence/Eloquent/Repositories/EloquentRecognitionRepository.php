<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Modules\Achievements\Domain\Recognitions\Recognition;
use Modules\Achievements\Domain\Recognitions\RecognitionType;
use Modules\Achievements\Domain\Repositories\RecognitionRepositoryInterface;
use stdClass;

final readonly class EloquentRecognitionRepository implements RecognitionRepositoryInterface
{
    private const string TABLE = 'achievements_recognitions';

    private const string TYPES_TABLE = 'achievements_recognition_types';

    private const string PROMPTS_TABLE = 'achievements_recognition_prompts';

    private const string STORAGE_FORMAT = 'Y-m-d H:i:s';

    private const int CHUNK = 500;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function give(string $typeId, string $giverId, string $receiverId, string $sourceType, string $subjectId, string $kind, DateTimeImmutable $givenAt): ?Recognition
    {
        $giverId = strtolower($giverId);
        $receiverId = strtolower($receiverId);
        // Random (v4), never time-ordered (v7): the id reaches both people and the panel, and a v7
        // id carries the exact instant it was given, which would point at the game
        $id = Str::uuid()->toString();
        $stamp = $this->stamp($givenAt);

        try {
            return $this->db->transaction(function () use ($id, $typeId, $giverId, $receiverId, $sourceType, $subjectId, $kind, $stamp): ?Recognition {
                // Under a shared lock, so an anonymization waits for this one and then deletes it
                // The rows themselves (PostgreSQL refuses FOR SHARE on an aggregate)
                $live = count(UserModel::withTrashed()->whereKey([$giverId, $receiverId])->whereNull('anonymized_at')->sharedLock()->pluck('id')->all());

                if ($live !== 2 || $this->exists($giverId, $receiverId, $sourceType, $subjectId)) {
                    return null;
                }

                $this->db->table(self::TABLE)->insert([
                    'id' => $id,
                    'type_id' => strtolower($typeId),
                    'giver_id' => $giverId,
                    'receiver_id' => $receiverId,
                    'source_type' => $sourceType,
                    'subject_id' => $subjectId,
                    'kind' => $kind,
                    'given_at' => $stamp,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ]);

                return $this->find($id);
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public function find(string $id): ?Recognition
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($id))->first();

        return $row instanceof stdClass ? $this->toEntity($row) : null;
    }

    public function delete(string $id): bool
    {
        return Str::isUuid($id) && $this->db->table(self::TABLE)->where('id', strtolower($id))->delete() > 0;
    }

    public function receivedBy(string $userId): array
    {
        return $this->entities($this->db->table(self::TABLE)->where('receiver_id', strtolower($userId)));
    }

    public function givenBy(string $userId): array
    {
        return $this->entities($this->db->table(self::TABLE)->where('giver_id', strtolower($userId)));
    }

    public function totals(array $userIds, ?DateTimeImmutable $before): array
    {
        $totals = [];

        foreach (array_chunk($this->ids($userIds), self::CHUNK) as $chunk) {
            $query = $this->db->table(self::TABLE)
                ->whereIn('receiver_id', $chunk)
                ->groupBy('receiver_id', 'type_id')
                ->select('receiver_id', 'type_id', $this->db->raw('COUNT(*) AS total'));

            if ($before instanceof DateTimeImmutable) {
                $query->where('given_at', '<', $this->stamp($before));
            }

            foreach ($query->get() as $row) {
                /** @var stdClass $row */
                $totals[strtolower((string) $row->receiver_id)][strtolower((string) $row->type_id)] = (int) $row->total;
            }
        }

        return $totals;
    }

    public function receivedInstants(array $userIds, ?string $typeId, DateTimeImmutable $before): array
    {
        $instants = [];
        $zone = new DateTimeZone(date_default_timezone_get());

        foreach (array_chunk($this->ids($userIds), self::CHUNK) as $chunk) {
            $query = $this->db->table(self::TABLE)
                ->whereIn('receiver_id', $chunk)
                ->where('given_at', '<', $this->stamp($before))
                ->orderBy('given_at')
                ->select('receiver_id', 'given_at');

            if ($typeId !== null) {
                $query->where('type_id', strtolower($typeId));
            }

            foreach ($query->get() as $row) {
                /** @var stdClass $row */
                $instants[strtolower((string) $row->receiver_id)][] = new DateTimeImmutable(substr((string) $row->given_at, 0, 19), $zone);
            }
        }

        return $instants;
    }

    public function receiverIds(DateTimeImmutable $before, ?string $afterUserId, int $limit): array
    {
        $query = $this->db->table(self::TABLE)
            ->where('given_at', '<', $this->stamp($before))
            ->distinct()
            ->orderBy('receiver_id')
            ->limit($limit);

        if ($afterUserId !== null) {
            $query->where('receiver_id', '>', strtolower($afterUserId));
        }

        return array_values(array_map(static fn (mixed $id): string => strtolower((string) $id), $query->pluck('receiver_id')->all()));
    }

    public function keepOnlyParticipants(string $sourceType, string $subjectId, array $userIds): array
    {
        $ids = $this->ids($userIds);
        $query = $this->db->table(self::TABLE)->where('source_type', $sourceType)->where('subject_id', $subjectId);

        if ($ids !== []) {
            $query->where(static fn (Builder $either): Builder => $either->whereNotIn('giver_id', $ids)->orWhereNotIn('receiver_id', $ids));
        }

        return $this->deleteAndList($query);
    }

    public function deleteForUser(string $userId): array
    {
        $userId = strtolower($userId);

        return $this->db->transaction(function () use ($userId): array {
            $this->db->table(self::PROMPTS_TABLE)->where('user_id', $userId)->delete();

            return $this->deleteAndList($this->db->table(self::TABLE)
                ->where(static fn (Builder $either): Builder => $either->where('giver_id', $userId)->orWhere('receiver_id', $userId)));
        });
    }

    /**
     * Deletes what the query finds and returns those ids (read under lock, then deleted by id).
     *
     * @return list<string>
     */
    private function deleteAndList(Builder $query): array
    {
        $ids = array_values(array_map(static fn (mixed $id): string => strtolower((string) $id), $query->lockForUpdate()->pluck('id')->all()));

        if ($ids !== []) {
            $this->db->table(self::TABLE)->whereIn('id', $ids)->delete();
        }

        return $ids;
    }

    public function prompted(string $userId, array $subjects): array
    {
        if ($subjects === []) {
            return [];
        }

        $asked = [];
        $rows = $this->db->table(self::PROMPTS_TABLE)
            ->where('user_id', strtolower($userId))
            ->whereIn('subject_id', array_values(array_unique(array_map(static fn (array $subject): string => $subject[1], $subjects))))
            ->get(['source_type', 'subject_id']);

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $asked[$row->source_type."\0".$row->subject_id] = true;
        }

        return array_values(array_filter($subjects, static fn (array $subject): bool => isset($asked[$subject[0]."\0".$subject[1]])));
    }

    public function markPrompted(string $userId, array $subjects, DateTimeImmutable $at): void
    {
        if ($subjects === []) {
            return;
        }

        $this->db->table(self::PROMPTS_TABLE)->insertOrIgnore(array_map(fn (array $subject): array => [
            'user_id' => strtolower($userId),
            'source_type' => $subject[0],
            'subject_id' => $subject[1],
            'prompted_at' => $this->stamp($at),
        ], $subjects));
    }

    public function types(): array
    {
        $types = [];

        foreach ($this->db->table(self::TYPES_TABLE)->orderBy('name')->orderBy('id')->get() as $row) {
            /** @var stdClass $row */
            $id = strtolower((string) $row->id);
            $types[$id] = new RecognitionType($id, (string) $row->name, (string) $row->icon, (string) $row->colour, (bool) $row->is_active);
        }

        return $types;
    }

    public function typeInUse(string $typeId): bool
    {
        return $this->db->table(self::TABLE)->where('type_id', strtolower($typeId))->exists();
    }

    private function exists(string $giverId, string $receiverId, string $sourceType, string $subjectId): bool
    {
        return $this->db->table(self::TABLE)
            ->where('giver_id', $giverId)
            ->where('receiver_id', $receiverId)
            ->where('source_type', $sourceType)
            ->where('subject_id', $subjectId)
            ->exists();
    }

    /**
     * @return list<Recognition>
     */
    private function entities(Builder $query): array
    {
        $entities = [];

        foreach ($query->orderByDesc('given_at')->orderByDesc('id')->get() as $row) {
            /** @var stdClass $row */
            $entities[] = $this->toEntity($row);
        }

        return $entities;
    }

    private function toEntity(stdClass $row): Recognition
    {
        return new Recognition(
            id: strtolower((string) $row->id),
            typeId: strtolower((string) $row->type_id),
            giverId: strtolower((string) $row->giver_id),
            receiverId: strtolower((string) $row->receiver_id),
            sourceType: (string) $row->source_type,
            subjectId: (string) $row->subject_id,
            kind: (string) $row->kind,
            givenAt: new DateTimeImmutable(substr((string) $row->given_at, 0, 19), new DateTimeZone(date_default_timezone_get())),
        );
    }

    private function stamp(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone(date_default_timezone_get()))->format(self::STORAGE_FORMAT);
    }

    /**
     * @param  list<string>  $userIds
     * @return list<string>
     */
    private function ids(array $userIds): array
    {
        return array_values(array_unique(array_map(strtolower(...), $userIds)));
    }
}
