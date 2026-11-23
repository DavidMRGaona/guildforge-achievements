<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Achievements\Application\DTOs\ChallengeViewDTO;

/**
 * What the site shows of the collective challenges: `/logros` and the `achievements.challenge` site
 * block. Nothing with the «Retos» switch off; a challenge whose rule cannot be read is not shown.
 * Bound "scoped": the catalogue is read once per request.
 */
interface ChallengeBoardInterface
{
    /** Challenges starting within these days are shown as «Próximamente» */
    public const int AHEAD_DAYS = 30;

    /** Challenges that ended within these days are still shown */
    public const int RECENT_DAYS = 30;

    /** People listed as having completed a challenge; the rest are only counted */
    public const int MAX_LISTED = 12;

    /**
     * The challenges of `/logros`, with whoever completed each one as the viewer may see them.
     *
     * @return list<ChallengeViewDTO>
     */
    public function shown(?Authenticatable $viewer): array;

    /**
     * One challenge for a site block, without the people: the one chosen (while the site shows it),
     * or with null the running one that ends first (else the next to start).
     */
    public function forBlock(?string $challengeId, ?Authenticatable $viewer): ?ChallengeViewDTO;

    /**
     * Every challenge that is not closed, for the block's settings in the panel: id => name.
     *
     * @return array<string, string>
     */
    public function blockOptions(): array;
}
