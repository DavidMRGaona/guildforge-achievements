<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Rules;

use Modules\Achievements\Domain\Enums\GrantSource;

/**
 * Which grants are worth telling others about (achievements#6), once the owner allowed it and the
 * mechanic is on: an achievement of a collective challenge, or one held by fewer people than the
 * threshold. Never a silent grant (a recalculation, an old achievement) and never a bulk grant: that
 * says who took part in one thing, which others only ever see as totals.
 */
final class AnnouncementRule
{
    /**
     * @param  float|null  $share  percent of active people holding it; null while unknown (nobody active)
     * @param  float  $threshold  percent under which an achievement is rare
     * @param  bool  $ownerShowsMembership  whether the owner's profile already shows the membership
     */
    public static function announces(GrantSource $source, bool $silent, ?float $share, float $threshold, bool $ownerShowsMembership = false): bool
    {
        if ($silent || $source === GrantSource::Bulk) {
            return false;
        }

        // The reward of a challenge for members says its holder is one: only when they show it anyway
        if ($source === GrantSource::MemberChallenge) {
            return $ownerShowsMembership;
        }

        if ($source === GrantSource::Challenge) {
            return true;
        }

        return $share !== null && $share < $threshold;
    }
}
