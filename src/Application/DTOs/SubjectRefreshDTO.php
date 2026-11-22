<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use App\Application\Activity\Enums\ActivityReadOutcome;
use Modules\Achievements\Domain\ValueObjects\FactsReplacement;

/**
 * What refreshing one subject did. `replacement` is set only when the source answered and its
 * facts were stored (or previewed); `Missing` and `Failed` changed nothing. An id that cannot be a
 * subject's is read with nothing to store.
 */
final readonly class SubjectRefreshDTO
{
    public function __construct(
        public ActivityReadOutcome $outcome,
        public ?FactsReplacement $replacement = null,
    ) {}

    public function isRead(): bool
    {
        return $this->outcome === ActivityReadOutcome::Read;
    }

    public function isFailed(): bool
    {
        return $this->outcome === ActivityReadOutcome::Failed;
    }
}
