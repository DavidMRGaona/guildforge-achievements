<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

/**
 * Decides who each newcomer's mentor is (MentorRule), once, for every newcomer whose first game is
 * past the grace and who was not decided yet. Run only by the nightly batch, before the mentor
 * achievements are evaluated; they count only what was decided here. Throws when the ledger cannot
 * be read, so the batch is tried again and nothing is decided on a guess.
 */
interface MentorDecisionsInterface
{
    /** Newcomers read from the ledger at once */
    public const int NEWCOMERS_PER_CHUNK = 500;

    /**
     * Answers how many newcomers were decided now.
     *
     * @phpstan-impure
     */
    public function decide(): int;
}
