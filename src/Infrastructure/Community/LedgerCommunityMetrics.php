<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Community;

use Illuminate\Contracts\Cache\Repository;
use Modules\Achievements\Application\Services\CommunityMetricsInterface;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\Rules\MetricType;
use Modules\Achievements\Infrastructure\Support\AchievementWarnings;
use Throwable;

/**
 * Progress is cached per account in one entry (`achievements:community:v1:<id>`) holding a count
 * per rule shape (metric, kind and roles: the threshold does not change who is counted), so
 * forgetting an account's progress is one key whatever the catalogue holds. A failure to read
 * the ledger is never cached.
 */
final readonly class LedgerCommunityMetrics implements CommunityMetricsInterface
{
    public const string CACHE_PREFIX = 'achievements:community:v1:';

    public const int CACHE_SECONDS = 300;

    public function __construct(
        private AchievementFactRepositoryInterface $facts,
        private Repository $cache,
        private AchievementWarnings $warnings,
    ) {}

    public function people(AchievementRule $rule, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return match ($rule->metric) {
            MetricType::People => $this->facts->firstMeetings($userIds, (string) $rule->kind, $rule->role, $rule->withRole),
            default => [],
        };
    }

    public function progress(AchievementRule $rule, string $userId): ?int
    {
        if (! $rule->isCommunity()) {
            return null;
        }

        $userId = strtolower($userId);
        $shape = $rule->peopleShape();
        $cached = $this->cached($userId);

        if (isset($cached[$shape])) {
            return $cached[$shape];
        }

        try {
            $count = count($this->people($rule, [$userId])[$userId] ?? []);
        } catch (Throwable $exception) {
            $this->warn('community_unavailable', $exception);

            return null;
        }

        try {
            $this->cache->put(self::CACHE_PREFIX.$userId, [$shape => $count] + $cached, self::CACHE_SECONDS);
        } catch (Throwable $exception) {
            // Still right for this request; the next one counts again
            $this->warn('community_cache_unwritable', $exception);
        }

        return $count;
    }

    public function forgetProgress(array $userIds): void
    {
        foreach (array_unique(array_map(strtolower(...), $userIds)) as $userId) {
            try {
                $this->cache->forget(self::CACHE_PREFIX.$userId);
            } catch (Throwable $exception) {
                $this->warn('community_cache_unwritable', $exception);
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function cached(string $userId): array
    {
        try {
            $cached = $this->cache->get(self::CACHE_PREFIX.$userId);
        } catch (Throwable $exception) {
            $this->warn('community_cache_unreadable', $exception);

            return [];
        }

        if (! is_array($cached)) {
            return [];
        }

        // Anything else in the entry is not trusted: counted again
        return array_filter($cached, static fn (mixed $count, mixed $shape): bool => is_string($shape) && is_int($count) && $count >= 0, ARRAY_FILTER_USE_BOTH);
    }

    private function warn(string $reason, Throwable $exception): void
    {
        $this->warnings->warn($reason, '[Achievements] The people a community achievement counts could not be read or kept.', [
            'reason' => $reason,
            'exception' => $exception::class,
        ]);
    }
}
