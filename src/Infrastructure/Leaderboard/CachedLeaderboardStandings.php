<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Leaderboard;

use Illuminate\Contracts\Cache\Repository;
use Modules\Achievements\Domain\Repositories\AchievementPointsRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\PointsBreakdown;
use Modules\Achievements\Domain\ValueObjects\PointsPeriod;
use Modules\Achievements\Infrastructure\Support\AchievementWarnings;
use Throwable;

/**
 * The standings kept in the cache for a few minutes: ids and points only (each account's points of
 * achievements that are not secret, and the id and points of each secret one it holds). They live
 * under a version token that every grant, revoke, change of an achievement's points or secrecy and
 * change of choices replaces. Who the viewer may see, which secrets are revealed to them and each
 * person's choices are worked out again on every request, never kept.
 *
 * Fails closed: a cache that cannot be read, or holds anything but that shape, gives the database's
 * answer; a cache that cannot be written changes nothing for this request.
 */
final readonly class CachedLeaderboardStandings
{
    public const string VERSION_KEY = 'achievements:leaderboard:version';

    public const string KEY_PREFIX = 'achievements:leaderboard:v2:';

    private const int CACHE_SECONDS = 600;

    public function __construct(
        private AchievementPointsRepositoryInterface $points,
        private Repository $cache,
        private AchievementWarnings $warnings,
    ) {}

    /**
     * @return array<string, PointsBreakdown> account id in lower case => its points
     */
    public function standings(PointsPeriod $period): array
    {
        $key = null;

        try {
            $version = $this->cache->get(self::VERSION_KEY);
            $key = self::KEY_PREFIX.$period->key().':'.(is_string($version) ? $version : '0');
            $cached = $this->read($this->cache->get($key));
        } catch (Throwable $exception) {
            $this->warn('leaderboard_cache_unreadable', $exception);
            $cached = null;
        }

        if ($cached !== null) {
            return $cached;
        }

        $standings = $this->points->standings($period);

        if ($key !== null) {
            try {
                $this->cache->put($key, $this->write($standings), self::CACHE_SECONDS);
            } catch (Throwable $exception) {
                $this->warn('leaderboard_cache_unwritable', $exception);
            }
        }

        return $standings;
    }

    public function forget(): void
    {
        try {
            $this->cache->forever(self::VERSION_KEY, bin2hex(random_bytes(8)));
        } catch (Throwable $exception) {
            // The kept standings expire on their own within minutes
            $this->warn('leaderboard_cache_unwritable', $exception);
        }
    }

    /**
     * @param  array<string, PointsBreakdown>  $standings
     * @return list<array{userId: string, open: int, secrets: array<string, int>}>
     */
    private function write(array $standings): array
    {
        $rows = [];

        foreach ($standings as $userId => $points) {
            $rows[] = ['userId' => $userId, 'open' => $points->open, 'secrets' => $points->secrets];
        }

        return $rows;
    }

    /**
     * @return array<string, PointsBreakdown>|null
     */
    private function read(mixed $cached): ?array
    {
        if (! is_array($cached) || ! array_is_list($cached)) {
            return null;
        }

        $standings = [];

        foreach ($cached as $row) {
            if (! is_array($row) || ! is_string($row['userId'] ?? null) || ! is_int($row['open'] ?? null) || $row['open'] < 0 || ! is_array($row['secrets'] ?? null)) {
                return null;
            }

            $secrets = [];

            foreach ($row['secrets'] as $id => $points) {
                if (! is_string($id) || ! is_int($points) || $points < 0) {
                    return null;
                }

                $secrets[$id] = $points;
            }

            $standings[$row['userId']] = new PointsBreakdown($row['open'], $secrets);
        }

        return $standings;
    }

    private function warn(string $reason, Throwable $exception): void
    {
        $this->warnings->warn($reason, '[Achievements] The leaderboard could not be read from or kept in the cache.', [
            'reason' => $reason,
            'exception' => $exception::class,
        ]);
    }
}
