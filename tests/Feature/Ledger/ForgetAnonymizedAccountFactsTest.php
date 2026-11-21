<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Ledger;

use App\Domain\Events\UserAnonymized;
use App\Domain\Events\UserDeactivated;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\MetricKinds;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Tests\Support\Modules\ModuleTestCase;

final class ForgetAnonymizedAccountFactsTest extends ModuleTestCase
{
    use BuildsActivityFacts;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger()->replaceSubject('game-tables.session', '42', MetricKinds::GAME_SESSION, [
            $this->recordedFact(self::ANA, 'director'),
            $this->recordedFact(self::LUIS),
        ]);
        $this->ledger()->replaceSubject('tournaments.tournament', '7', MetricKinds::TOURNAMENT, [
            $this->recordedFact(self::ANA, 'organizer'),
        ]);
    }

    public function test_an_anonymized_account_loses_every_fact(): void
    {
        event(new UserAnonymized(self::ANA));

        $this->assertSame([], $this->ledger()->forUser(self::ANA));
        $this->assertCount(1, $this->ledger()->forUser(self::LUIS));
    }

    public function test_a_deactivated_account_keeps_its_facts(): void
    {
        event(new UserDeactivated(self::ANA));

        $this->assertCount(2, $this->ledger()->forUser(self::ANA));
    }

    private function ledger(): AchievementFactRepositoryInterface
    {
        return $this->app->make(AchievementFactRepositoryInterface::class);
    }
}
