<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use App\Application\Services\TimezoneServiceInterface;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\ValueObjects\Season;

/**
 * The season going on: the year of today in the display zone (TimezoneServiceInterface::today()),
 * never the server's clock or the storage zone. A grant by hand or in bulk of a repeatable
 * achievement goes to it; so do the progress and the «this season» of the pages.
 */
final readonly class SeasonClock
{
    public function __construct(
        private TimezoneServiceInterface $time,
    ) {}

    public function current(): Season
    {
        return Season::containing($this->time->today(), $this->time->displayTimezone());
    }

    /**
     * The season a new grant of this achievement goes to: the current one when it repeats per
     * season, none otherwise (also for an unreadable rule).
     */
    public function forGrantOf(Achievement|AchievementRule|null $achievement): ?Season
    {
        $rule = $achievement instanceof Achievement ? $achievement->rule : $achievement;

        return $rule instanceof AchievementRule && $rule->isSeasonal() ? $this->current() : null;
    }

    /**
     * The season key a new grant of this achievement is stored under ('' without a season).
     */
    public function keyForGrantOf(Achievement|AchievementRule|null $achievement): string
    {
        return $this->forGrantOf($achievement)?->key() ?? '';
    }
}
