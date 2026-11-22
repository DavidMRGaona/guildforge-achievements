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
     */
    public static function announces(GrantSource $source, bool $silent, ?float $share, float $threshold): bool
    {
        if ($silent || $source === GrantSource::Bulk) {
            return false;
        }

        if ($source === GrantSource::Challenge) {
            return true;
        }

        return $share !== null && $share < $threshold;
    }
}
