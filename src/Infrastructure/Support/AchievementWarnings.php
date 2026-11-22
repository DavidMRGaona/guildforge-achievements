<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Support;

use App\Infrastructure\Support\DailyWarnings;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * The module's warnings, once a day each (prefix `achievements:warned:`). Context holds ids and
 * reasons only, never names, rules or exception messages.
 */
final readonly class AchievementWarnings
{
    private const string PREFIX = 'achievements:warned:';

    private DailyWarnings $warnings;

    public function __construct(Application $app)
    {
        $this->warnings = new DailyWarnings(self::PREFIX, static fn (): Repository => $app->make('cache.store'));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warn(string $id, string $message, array $context = []): void
    {
        $this->warnings->warn($id, $message, $context);
    }
}
