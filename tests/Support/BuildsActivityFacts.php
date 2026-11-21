<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use App\Application\Activity\DTOs\ActivityAttributeValueDTO;
use App\Application\Activity\DTOs\ActivityFactDTO;
use App\Application\Activity\Services\ActivitySourceRegistryInterface;
use App\Domain\Activity\ActivityRole;
use DateTimeImmutable;
use Modules\Achievements\Domain\ValueObjects\FactAttribute;
use Modules\Achievements\Domain\ValueObjects\RecordedFact;
use Tests\Support\Activity\InMemoryActivitySource;

/**
 * Facts as a source reports them and as the ledger stores them, and the core's in-memory source
 * registered as a module would register its own.
 */
trait BuildsActivityFacts
{
    protected const string ANA = '0195f3a2-7c1e-7d4b-9a8e-2f6c1b3d4e5f';

    protected const string LUIS = '0195f3a2-7c1e-7d4b-9a8e-2f6c1b3d4e60';

    protected const string MARTA = '0195f3a2-7c1e-7d4b-9a8e-2f6c1b3d4e61';

    protected function activityFact(
        string $userId,
        ActivityRole $role = ActivityRole::Player,
        string $when = '2026-03-14 18:00:00',
        ?string $system = null,
        ?int $placement = null,
    ): ActivityFactDTO {
        return new ActivityFactDTO(
            $userId,
            $role,
            new DateTimeImmutable($when),
            $system === null ? [] : ['game_system' => new ActivityAttributeValueDTO($system, ucfirst($system))],
            $placement,
        );
    }

    protected function recordedFact(
        string $userId,
        string $role = 'player',
        string $when = '2026-03-14 18:00:00',
        ?string $system = null,
        ?int $placement = null,
    ): RecordedFact {
        return new RecordedFact(
            $userId,
            $role,
            new DateTimeImmutable($when),
            $system === null ? [] : ['game_system' => new FactAttribute($system, ucfirst($system))],
            $placement,
        );
    }

    protected function registerInMemorySource(): void
    {
        InMemoryActivitySource::reset();
        $this->app->make(ActivitySourceRegistryInterface::class)->register(InMemoryActivitySource::class);
    }

    /**
     * @param  class-string  $class
     */
    protected function registerSource(string $class): void
    {
        $this->app->make(ActivitySourceRegistryInterface::class)->register($class, 'achievements');
    }
}
