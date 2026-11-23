<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Challenges;

use DateTimeImmutable;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;

/**
 * Who among some people holds a challenge's reward now (a reward taken away by hand no longer
 * counts), in the order given; with `$grantedBy`, only those who got it by then (the nightly photo,
 * as others see each profile).
 */
final readonly class ChallengeHolders
{
    private const int CHUNK = 500;

    public function __construct(
        private AchievementGrantRepositoryInterface $grants,
    ) {}

    /**
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function among(string $rewardId, array $userIds, ?DateTimeImmutable $grantedBy = null): array
    {
        if ($grantedBy instanceof DateTimeImmutable) {
            return $this->grantedBy($rewardId, $userIds, $grantedBy);
        }

        $holders = [];

        foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
            $held = $this->grants->heldAchievementIds($chunk);

            foreach ($chunk as $userId) {
                if (in_array($rewardId, $held[$userId] ?? [], true)) {
                    $holders[] = $userId;
                }
            }
        }

        return $holders;
    }

    /**
     * @param  list<string>  $userIds
     * @return list<string>
     */
    private function grantedBy(string $rewardId, array $userIds, DateTimeImmutable $grantedBy): array
    {
        $holders = [];

        foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
            $grants = $this->grants->forUsers($chunk);

            foreach ($chunk as $userId) {
                $got = array_any(
                    $grants[strtolower($userId)] ?? [],
                    static fn (AchievementGrant $grant): bool => strtolower($grant->achievementId) === $rewardId && $grant->grantedAt <= $grantedBy,
                );

                if ($got) {
                    $holders[] = $userId;
                }
            }
        }

        return $holders;
    }
}
