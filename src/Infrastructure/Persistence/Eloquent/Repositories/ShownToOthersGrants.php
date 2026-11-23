<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Modules\Achievements\Domain\Rules\MetricType;
use Modules\Achievements\Domain\Rules\ShownToOthersRule;

/**
 * ShownToOthersRule in a query on `achievements_grants`: made before the photo and, for the
 * achievements whose stored rule is shown by closed months, before the photo's month. The rule's
 * metric is read from the stored JSON, so an achievement whose rule no other check can read is held
 * back too. Instants are bound in the storage zone (PHP's default zone), as the bindings skip the
 * models' conversion.
 */
final class ShownToOthersGrants
{
    private const string STORAGE_FORMAT = 'Y-m-d H:i:s';

    public static function apply(Builder $query, DateTimeImmutable $takenAt, DateTimeZone $displayZone, string $grants = 'achievements_grants'): Builder
    {
        $storage = new DateTimeZone(date_default_timezone_get());
        $before = $takenAt->setTimezone($storage)->format(self::STORAGE_FORMAT);
        $monthStart = ShownToOthersRule::closedMonthsBefore($takenAt, $displayZone)->setTimezone($storage)->format(self::STORAGE_FORMAT);
        $byClosedMonths = array_map(
            static fn (MetricType $metric): string => $metric->value,
            array_values(array_filter(MetricType::cases(), static fn (MetricType $metric): bool => $metric->showsByClosedMonths())),
        );

        return $query
            ->where($grants.'.granted_at', '<', $before)
            ->where(static fn (Builder $shown): Builder => $shown
                ->whereNotIn($grants.'.achievement_id', static fn (Builder $closed): Builder => $closed
                    ->from('achievements_catalogue as shown_by_closed_months')
                    ->select('shown_by_closed_months.id')
                    ->whereIn('shown_by_closed_months.rule->metric', $byClosedMonths))
                ->orWhere($grants.'.granted_at', '<', $monthStart));
    }
}
