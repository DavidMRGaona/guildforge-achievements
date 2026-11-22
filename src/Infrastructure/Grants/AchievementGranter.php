<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Grants;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Events\AchievementGranted;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\NewGrant;

final readonly class AchievementGranter implements AchievementGranterInterface
{
    public function __construct(
        private AchievementGrantRepositoryInterface $grants,
        private Dispatcher $events,
    ) {}

    public function grant(NewGrant $grant): ?AchievementGrant
    {
        $granted = $this->grants->grant($grant);

        if ($granted instanceof AchievementGrant) {
            // AchievementGranted waits for the commit by itself (ShouldDispatchAfterCommit)
            $this->events->dispatch(new AchievementGranted(
                grantId: $granted->id,
                userId: $granted->userId,
                achievementId: $granted->achievementId,
                source: $granted->source->value,
                silent: $granted->silent,
                season: $granted->season,
            ));
        }

        return $granted;
    }
}
