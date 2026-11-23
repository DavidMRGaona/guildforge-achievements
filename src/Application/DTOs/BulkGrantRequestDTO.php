<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use App\Domain\Activity\ActivityRole;

/**
 * A bulk grant asked for: the achievement, to whoever held `role` in one subject of an activity
 * source (an event, a tournament, a table…). `season` is the season chosen for one that repeats
 * (SeasonClock::choices(); the current one when null), ignored for the rest.
 */
final readonly class BulkGrantRequestDTO
{
    public function __construct(
        public string $sourceType,
        public string $subjectId,
        public ActivityRole $role,
        public string $achievementId,
        public ?string $season = null,
    ) {}
}
