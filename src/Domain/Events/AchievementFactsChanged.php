<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Events;

/**
 * The ledger was refreshed for these accounts: their achievements must be checked again.
 * Internal to the module (the rule evaluator listens to it).
 *
 * `silent` is true when it comes from a recalculation: whatever it grants is granted without a
 * notice. The ids are sorted and each appears once.
 */
final readonly class AchievementFactsChanged
{
    /**
     * @param  list<string>  $userIds
     */
    public function __construct(
        public array $userIds,
        public bool $silent = false,
    ) {}
}
