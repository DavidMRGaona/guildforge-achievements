<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Points;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Modules\Achievements\Application\Services\PointsCutoffInterface;
use Throwable;

/**
 * The cut until the nightly photo of #28 reaches main: midnight of today in the display zone, so
 * others see a day's grants from the next day on. Read once per request or job (bound "scoped");
 * any failure gives no cut, so nothing is shown to others.
 */
final class MidnightPointsCutoff implements PointsCutoffInterface
{
    private bool $read = false;

    private ?DateTimeImmutable $takenAt = null;

    public function __construct(
        private readonly TimezoneServiceInterface $time,
    ) {}

    public function takenAt(): ?DateTimeImmutable
    {
        if (! $this->read) {
            $this->read = true;

            try {
                $this->takenAt = $this->time->toStorage($this->time->today());
            } catch (Throwable) {
                $this->takenAt = null;
            }
        }

        return $this->takenAt;
    }
}
