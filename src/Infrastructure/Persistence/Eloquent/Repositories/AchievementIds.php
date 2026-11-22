<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

/**
 * Achievement ids are UUIDs; anything else is never looked up (PostgreSQL would reject it).
 */
final class AchievementIds
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    public static function isWellFormed(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }
}
