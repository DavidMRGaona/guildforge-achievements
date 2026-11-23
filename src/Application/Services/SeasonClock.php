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
     * The seasons a grant by hand or in bulk may go to: the current one (the default) and the one
     * before, for what was done just before the year changed. Keyed by season key, newest first.
     *
     * @return array<string, Season>
     */
    public function choices(): array
    {
        $current = $this->current();
        $choices = [$current->key() => $current];
        $previous = $current->previous();

        if ($previous instanceof Season) {
            $choices[$previous->key()] = $previous;
        }

        return $choices;
    }

    /**
     * The key a grant by hand or in bulk of this achievement goes to: '' for one that does not
     * repeat, the chosen season when it is one of choices() (the current one when none was chosen),
     * and null for any other choice.
     */
    public function keyForChoice(Achievement|AchievementRule|null $achievement, mixed $chosen): ?string
    {
        if (! $this->forGrantOf($achievement) instanceof Season) {
            return '';
        }

        // A year as an array key, or a form option built from one, comes back as a number
        if (is_int($chosen)) {
            $chosen = (string) $chosen;
        }

        if ($chosen === null || $chosen === '') {
            return $this->current()->key();
        }

        $choices = $this->choices();

        return is_string($chosen) && isset($choices[$chosen]) ? $choices[$chosen]->key() : null;
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
