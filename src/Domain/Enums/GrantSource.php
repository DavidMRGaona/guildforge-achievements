<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Enums;

/**
 * Why someone holds an achievement: a rule met (this module's evaluator), a person granting it by
 * hand to one account or to several (#4), or a collective challenge completed (#12). A challenge for
 * association members has a source of its own: its reward says its holder is one, so others are
 * told of it only when the holder shows that anyway.
 */
enum GrantSource: string
{
    case Rule = 'rule';

    case Manual = 'manual';

    case Bulk = 'bulk';

    case Challenge = 'challenge';

    case MemberChallenge = 'member_challenge';

    public function isChallenge(): bool
    {
        return $this === self::Challenge || $this === self::MemberChallenge;
    }
}
