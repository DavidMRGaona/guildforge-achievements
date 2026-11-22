<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Leaderboard;

use Illuminate\Contracts\Cache\Repository;
use Modules\Achievements\Domain\Repositories\AchievementPointsRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\PointsPeriod;
use Modules\Achievements\Infrastructure\Support\AchievementWarnings;
use Throwable;

/**
 * The standings (account ids and points, nothing else) kept in the cache for a few minutes under a
 * version token that every grant, revoke and change of points or choices replaces. Only points are
 * kept: who the viewer may see, and each person's choices, are read again on every request.
 *
 * Fails closed: a cache that cannot be read, or holds anything but a list of id and points, gives
 * the database's answer; a cache that cannot be written changes nothing for this request.
 */
final readonly class CachedLeaderboardStandings
{
    public const string VERSION_KEY = 'achievements:leaderboard:version';

    private const string KEY_PREFIX = 'achievements:leaderboard:v1:';

    private const int CACHE_SECONDS = 600;

    public function __construct(
        private AchievementPointsRepositoryInterface $points,
        private Repository $cache,
        private AchievementWarnings $warnings,
    ) {}

    /**
     * @return list<array{userId: string, points: int}>
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
                $this->cache->put($key, $standings, self::CACHE_SECONDS);
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
     * @return list<array{userId: string, points: int}>|null
     */
    private function read(mixed $cached): ?array
    {
        if (! is_array($cached) || ! array_is_list($cached)) {
            return null;
        }

        $standings = [];

        foreach ($cached as $row) {
            if (! is_array($row) || ! is_string($row['userId'] ?? null) || ! is_int($row['points'] ?? null) || $row['points'] <= 0) {
                return null;
            }

            $standings[] = ['userId' => $row['userId'], 'points' => $row['points']];
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
