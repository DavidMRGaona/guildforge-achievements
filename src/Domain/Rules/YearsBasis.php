<?php

declare(strict_types=1);

namespace Modules\Achievements\Domain\Rules;

use App\Domain\Activity\ActivityKind;

/**
 * What a years rule counts: full years since the account's fact (core source `core.account`), or
 * the membership years the memberships module reports (one subject per year).
 */
enum YearsBasis: string
{
    case Account = 'account';

    case Membership = 'membership';

    public function kind(): string
    {
        return match ($this) {
            self::Account => ActivityKind::Account->value,
            self::Membership => ActivityKind::MembershipYear->value,
        };
    }
}
