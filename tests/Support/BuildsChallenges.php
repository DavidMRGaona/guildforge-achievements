<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\ChallengeEvaluatorInterface;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\ChallengeModel;

/**
 * Collective challenges written straight to their table, and the «Retos» switch.
 */
trait BuildsChallenges
{
    protected function switchChallenges(bool $on = true): void
    {
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(challenges: $on));
        $this->app->forgetScopedInstances();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function reward(array $attributes = []): AchievementModel
    {
        return $this->achievement(['version' => 1, 'metric' => 'manual'], $attributes + ['name' => 'Recompensa '.uniqid()]);
    }

    /**
     * @param  array<string, mixed>  $rule  merged over 40 game sessions
     * @param  array<string, mixed>  $attributes
     */
    protected function challenge(array $rule = [], array $attributes = []): ChallengeModel
    {
        $model = new ChallengeModel;
        $model->fill($attributes + [
            'name' => 'Reto '.uniqid(),
            'description' => 'Entre todos',
            'rule' => $rule + ['version' => 1, 'metric' => 'subjects', 'kind' => 'game_session', 'target' => 40],
            'audience' => 'everybody',
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-31',
            'reward_achievement_id' => array_key_exists('reward_achievement_id', $attributes) ? $attributes['reward_achievement_id'] : $this->reward()->id,
        ]);
        $model->save();

        return $model;
    }

    protected function challenges(): ChallengeEvaluatorInterface
    {
        return $this->app->make(ChallengeEvaluatorInterface::class);
    }
}
