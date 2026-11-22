<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\ValueObjects;

use Modules\Achievements\Domain\Enums\GrantSource;

/**
 * A grant asked for: to whom, what, why and by whom. `season` stays null until repeatable
 * achievements (#8). `reason` is the optional note of a grant by hand or in bulk, kept in the
 * grant history (through GrantReason).
 */
final readonly class NewGrant
{
    public function __construct(
        public string $userId,
        public string $achievementId,
        public GrantSource $source,
        public bool $silent,
        public ?string $actorId = null,
        public ?string $season = null,
        public ?string $reason = null,
    ) {}
}
