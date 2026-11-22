<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Observers;

use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;

/**
 * Featuring a secret achievement reveals it to everybody, so only a choice made while it was secret
 * may do so: an achievement that becomes secret leaves everybody's featured badges, and nothing stays
 * revealed that nobody chose to reveal.
 */
final readonly class ForgetFeaturedWhenMadeSecret
{
    public function __construct(
        private AchievementPreferencesRepositoryInterface $preferences,
    ) {}

    /**
     * "updated", not "saved": it fires only after a real update, with that update's changes.
     */
    public function updated(AchievementModel $achievement): void
    {
        if ($achievement->is_secret && $achievement->wasChanged('is_secret')) {
            $this->preferences->forgetFeatured((string) $achievement->id);
        }
    }
}
