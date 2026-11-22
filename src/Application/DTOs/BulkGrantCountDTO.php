<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use Modules\Achievements\Application\Enums\BulkGrantOutcome;

/**
 * What a bulk grant would do (preview) or did, in numbers only: who played or attended is not public,
 * so whoever grants learns how many, never who. `people` held the role; of them, `alreadyHeld` had
 * the achievement and `revoked` had it taken away (never granted again in bulk). `granted` is what a
 * grant actually wrote (0 in a preview).
 */
final readonly class BulkGrantCountDTO
{
    public function __construct(
        public BulkGrantOutcome $outcome,
        public int $people = 0,
        public int $alreadyHeld = 0,
        public int $revoked = 0,
        public int $granted = 0,
    ) {}

    public static function refused(BulkGrantOutcome $outcome): self
    {
        return new self($outcome);
    }

    public function toGrant(): int
    {
        return max(0, $this->people - $this->alreadyHeld - $this->revoked);
    }
}
