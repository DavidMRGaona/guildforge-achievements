<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Unit;

use Modules\Achievements\Domain\Challenges\ChallengeRewardRule;
use Modules\Achievements\Domain\Rules\AchievementRule;
use PHPUnit\Framework\TestCase;

final class ChallengeRewardRuleTest extends TestCase
{
    public function test_a_reward_is_switched_on_not_secret_and_granted_by_hand_once(): void
    {
        $manual = AchievementRule::manual()->toArray();

        $this->assertTrue(ChallengeRewardRule::allows(true, false, $manual));
        $this->assertFalse(ChallengeRewardRule::allows(false, false, $manual));
        $this->assertFalse(ChallengeRewardRule::allows(true, true, $manual));
        $this->assertFalse(ChallengeRewardRule::allows(true, false, ['version' => 1, 'metric' => 'count', 'kind' => 'event', 'threshold' => 1]));
        $this->assertFalse(ChallengeRewardRule::allows(true, false, 'broken'));
    }

    public function test_a_reward_repeated_each_season_is_not_one(): void
    {
        $seasonal = AchievementRule::manual(perSeason: true);

        $this->assertTrue($seasonal->isSeasonal());
        $this->assertFalse(ChallengeRewardRule::allows(true, false, $seasonal->toArray()));
    }
}
