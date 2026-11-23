<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Challenges;

use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;

/**
 * Who among some people holds a challenge's reward now (a reward taken away by hand no longer
 * counts), in the order given.
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
    public function among(string $rewardId, array $userIds): array
    {
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
}
