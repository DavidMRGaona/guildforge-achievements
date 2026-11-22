<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use App\Domain\Activity\ActivityKind;
use RuntimeException;
use Tests\Support\Activity\FakeActivitySource;

/**
 * A tournament source that reads its type and then fails to give its kind: registered, yet
 * unavailable, as a module whose dependency is down.
 */
final class BrokenTournamentSource extends FakeActivitySource
{
    public const string TYPE = 'achievements.broken-tournaments';

    public function type(): string
    {
        return self::TYPE;
    }

    public function kind(): ActivityKind
    {
        throw new RuntimeException('The source is down.');
    }
}
