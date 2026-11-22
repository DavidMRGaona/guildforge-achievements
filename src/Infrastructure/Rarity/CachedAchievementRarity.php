<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Rarity;

use App\Application\Services\TimezoneServiceInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Achievements\Application\DTOs\AchievementRarityDTO;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Infrastructure\Support\AchievementWarnings;
use Throwable;

/**
 * Two aggregate queries over the ledger and the grants, answered from the indexes on
 * `achievements_facts (occurred_at, user_id)` and the grants' unique key:
 *
 * - active people: accounts with a fact since the same display day twelve months ago, joined to
 *   `users` (so an account deleted for good, whose facts stay without a foreign key, never counts)
 *   and neither deactivated (`deleted_at`) nor anonymized;
 * - holders: of those people, how many hold each achievement (any season).
 *
 * Kept in the cache for two days: the nightly job and every grant write it again long before.
 * Bound "scoped", so a page asks the cache once per request.
 */
final class CachedAchievementRarity implements AchievementRarityInterface
{
    public const string CACHE_KEY = 'achievements:rarity:v1';

    public const int MONTHS_ACTIVE = 12;

    private const int CACHE_SECONDS = 172_800;

    private const string STORAGE_FORMAT = 'Y-m-d H:i:s';

    private ?AchievementRarityDTO $read = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Repository $cache,
        private readonly TimezoneServiceInterface $time,
        private readonly AchievementWarnings $warnings,
    ) {}

    public function current(): AchievementRarityDTO
    {
        if ($this->read instanceof AchievementRarityDTO) {
            return $this->read;
        }

        try {
            $cached = AchievementRarityDTO::fromArray($this->cache->get(self::CACHE_KEY));
        } catch (Throwable $exception) {
            $this->warn('cache_unreadable', $exception);
            $cached = null;
        }

        if ($cached instanceof AchievementRarityDTO) {
            return $this->read = $cached;
        }

        try {
            return $this->read = $this->refresh();
        } catch (Throwable $exception) {
            $this->warn('rarity_unavailable', $exception);

            return $this->read = AchievementRarityDTO::unknown();
        }
    }

    public function refresh(): AchievementRarityDTO
    {
        $rarity = $this->compute();
        $this->read = $rarity;

        try {
            $this->cache->put(self::CACHE_KEY, $rarity->toArray(), self::CACHE_SECONDS);
        } catch (Throwable $exception) {
            // Still right for this request; the next one computes it again
            $this->warn('cache_unwritable', $exception);
        }

        return $rarity;
    }

    private function compute(): AchievementRarityDTO
    {
        $since = $this->time->toStorage($this->time->today()->modify('-'.self::MONTHS_ACTIVE.' months'))->format(self::STORAGE_FORMAT);
        $activePeople = $this->activePeople($since)->distinct()->count('achievements_facts.user_id');
        $shares = [];

        if ($activePeople > 0) {
            $rows = $this->db->table('achievements_grants')
                ->whereIn('user_id', $this->activePeople($since)->distinct()->select('achievements_facts.user_id'))
                ->selectRaw('achievement_id, count(distinct user_id) as holders')
                ->groupBy('achievement_id')
                ->get();

            foreach ($rows as $row) {
                $shares[strtolower((string) $row->achievement_id)] = round(100 * (int) $row->holders / $activePeople, 2);
            }
        }

        return new AchievementRarityDTO($activePeople, $shares, $this->time->iso(now()));
    }

    private function activePeople(string $since): Builder
    {
        return $this->db->table('achievements_facts')
            ->join('users', 'users.id', '=', 'achievements_facts.user_id')
            ->where('achievements_facts.occurred_at', '>=', $since)
            ->whereNull('users.deleted_at')
            ->whereNull('users.anonymized_at');
    }

    private function warn(string $reason, Throwable $exception): void
    {
        $this->warnings->warn($reason, '[Achievements] The rarity of the achievements could not be read or kept.', [
            'reason' => $reason,
            'exception' => $exception::class,
        ]);
    }
}
