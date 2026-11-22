<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use JsonException;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\AchievementPreferences;

final readonly class EloquentAchievementPreferencesRepository implements AchievementPreferencesRepositoryInterface
{
    public const string TABLE = 'achievements_preferences';

    private const int CHUNK = 500;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function forUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map(strtolower(...), $userIds)));
        $found = [];

        foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
            foreach ($this->table()->whereIn('user_id', $chunk)->get(['user_id', 'hidden', 'featured']) as $row) {
                $userId = strtolower((string) $row->user_id);
                $found[$userId] = new AchievementPreferences($userId, (bool) $row->hidden, $this->featured($row->featured));
            }
        }

        $preferences = [];

        foreach ($userIds as $userId) {
            $preferences[$userId] = $found[$userId] ?? AchievementPreferences::none($userId);
        }

        return $preferences;
    }

    public function find(string $userId): AchievementPreferences
    {
        $userId = strtolower($userId);

        return $this->forUsers([$userId])[$userId];
    }

    public function save(AchievementPreferences $preferences): void
    {
        $now = now();

        $this->table()->upsert(
            [[
                'user_id' => strtolower($preferences->userId),
                'hidden' => $preferences->hidden,
                'featured' => json_encode($preferences->featured, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id'],
            ['hidden', 'featured', 'updated_at'],
        );
    }

    public function forgetFeatured(string $achievementId): int
    {
        $achievementId = strtolower($achievementId);

        if (! AchievementIds::isWellFormed($achievementId)) {
            return 0;
        }

        $changed = 0;

        // A text match finds the candidates on every database (PostgreSQL has no LIKE for json, hence
        // the cast; the id is a checked uuid, so it holds no wildcard); each row is then read as JSON
        $rows = $this->table()
            ->whereRaw('CAST(featured AS TEXT) LIKE ?', ['%'.$achievementId.'%'])
            ->get(['user_id', 'featured']);

        foreach ($rows as $row) {
            $featured = self::featured($row->featured);
            $kept = array_values(array_filter($featured, static fn (string $id): bool => $id !== $achievementId));

            if ($kept === $featured) {
                continue;
            }

            $this->table()->where('user_id', (string) $row->user_id)->update([
                'featured' => json_encode($kept, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $changed++;
        }

        return $changed;
    }

    public function deleteForUser(string $userId): void
    {
        $this->table()->where('user_id', strtolower($userId))->delete();
    }

    private function table(): Builder
    {
        return $this->db->table(self::TABLE);
    }

    /**
     * Anything but a JSON list of ids reads as «not chosen».
     *
     * @return list<string>
     */
    private function featured(mixed $stored): array
    {
        if (! is_string($stored) || $stored === '') {
            return [];
        }

        try {
            $decoded = json_decode($stored, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) && array_is_list($decoded) ? AchievementPreferences::normalise($decoded) : [];
    }
}
