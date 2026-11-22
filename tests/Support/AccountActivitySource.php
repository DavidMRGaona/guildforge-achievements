<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use App\Domain\Activity\ActivityKind;
use Tests\Support\Activity\FakeActivitySource;

/**
 * A source of the account kind (as the core's `core.account` is), with no subjects of its own: tests
 * write its facts straight to the ledger.
 */
final class AccountActivitySource extends FakeActivitySource
{
    public const string TYPE = 'achievements.accounts';

    public function type(): string
    {
        return self::TYPE;
    }

    public function kind(): ActivityKind
    {
        return ActivityKind::Account;
    }
}
