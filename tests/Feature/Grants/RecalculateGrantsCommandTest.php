<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Grants;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Console\Commands\RecalculateAchievementFactsCommand;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;

final class RecalculateGrantsCommandTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutStarterCatalogue();

        $this->registerInMemorySource();
        Bus::fake([EvaluateAchievementsJob::class]);
        $this->app->make(Kernel::class)->registerCommand($this->app->make(RecalculateAchievementFactsCommand::class));
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();

        parent::tearDown();
    }

    public function test_one_achievement_is_evaluated_for_everybody_from_the_ledger_silently(): void
    {
        $ana = $this->account(self::ANA);
        $luis = $this->account(self::LUIS);
        $achievement = $this->achievement($this->countRule());
        $other = $this->achievement($this->countRule());
        $this->ledgerSubject(InMemoryActivitySource::TYPE, '42', 'game_session', [$this->recordedFact($ana), $this->recordedFact($luis)]);
        // The source says nothing now: it must not be read
        InMemoryActivitySource::reset();

        $this->recalculate(['--achievement' => $achievement->id])
            ->expectsOutputToContain('concedidos: 2')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(2, DB::table('achievements_grants')->where('achievement_id', $achievement->id)->where('silent', true)->count());
        $this->assertSame(0, DB::table('achievements_grants')->where('achievement_id', $other->id)->count());
        $this->assertCount(2, $this->ledger()->userIdsOfSubject(InMemoryActivitySource::TYPE, '42'));
    }

    public function test_one_account_is_evaluated_alone(): void
    {
        $ana = $this->account(self::ANA);
        $luis = $this->account(self::LUIS);
        $this->achievement($this->countRule());
        $this->ledgerSubject(InMemoryActivitySource::TYPE, '42', 'game_session', [$this->recordedFact($ana), $this->recordedFact($luis)]);

        $this->recalculate(['--user' => strtoupper($ana)])
            ->expectsOutputToContain('concedidos: 1')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame([$ana], DB::table('achievements_grants')->pluck('user_id')->all());
    }

    public function test_both_options_together_evaluate_one_achievement_for_one_account(): void
    {
        $ana = $this->account(self::ANA);
        $achievement = $this->achievement($this->countRule());
        $this->achievement($this->countRule());
        $this->ledgerSubject(InMemoryActivitySource::TYPE, '42', 'game_session', [$this->recordedFact($ana)]);

        $this->recalculate(['--user' => $ana, '--achievement' => $achievement->id])->assertExitCode(Command::SUCCESS);

        $this->assertSame([$achievement->id], DB::table('achievements_grants')->pluck('achievement_id')->all());
    }

    public function test_a_dry_run_counts_without_granting(): void
    {
        $ana = $this->account(self::ANA);
        $achievement = $this->achievement($this->countRule());
        $this->ledgerSubject(InMemoryActivitySource::TYPE, '42', 'game_session', [$this->recordedFact($ana)]);

        $this->recalculate(['--achievement' => $achievement->id, '--dry-run' => true])
            ->expectsOutputToContain('se concederían: 1')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(0, DB::table('achievements_grants')->count());
    }

    public function test_a_dry_run_never_counts_an_achievement_taken_away(): void
    {
        $ana = $this->account(self::ANA);
        $luis = $this->account(self::LUIS);
        $achievement = $this->achievement($this->countRule());
        $granter = $this->app->make(AchievementGranterInterface::class);
        $grant = $granter->grant(new NewGrant($ana, $achievement->id, GrantSource::Manual, silent: true));
        $this->assertInstanceOf(AchievementGrant::class, $grant);
        $granter->revoke($grant->id, $luis, 'Partida falsa');
        $this->ledgerSubject(InMemoryActivitySource::TYPE, '42', 'game_session', [$this->recordedFact($ana), $this->recordedFact($luis)]);

        $this->recalculate(['--achievement' => $achievement->id, '--dry-run' => true])
            ->expectsOutputToContain('se concederían: 1')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_an_unknown_achievement_or_a_malformed_account_is_refused(): void
    {
        $this->recalculate(['--achievement' => (string) Str::uuid()])
            ->expectsOutputToContain('No existe ningún logro')
            ->assertExitCode(Command::INVALID);
        $this->recalculate(['--achievement' => 'diez-partidas'])->assertExitCode(Command::INVALID);
        $this->recalculate(['--user' => 'ana'])
            ->expectsOutputToContain('no es el id de una cuenta')
            ->assertExitCode(Command::INVALID);
    }

    public function test_the_source_option_does_not_combine_with_the_others(): void
    {
        $this->recalculate(['--source' => InMemoryActivitySource::TYPE, '--user' => self::ANA])
            ->expectsOutputToContain('--source')
            ->assertExitCode(Command::INVALID);
    }

    public function test_an_achievement_that_is_not_evaluated_now_says_why(): void
    {
        $achievement = $this->achievement($this->countRule('event'));

        $this->recalculate(['--achievement' => $achievement->id])
            ->expectsOutputToContain('Sin fuente')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_settings_that_cannot_be_read_fail_the_command(): void
    {
        $achievement = $this->achievement($this->countRule());
        DB::table('settings')->insert(['id' => (string) Str::uuid(), 'key' => 'module_settings:achievements', 'value' => 'nope', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->forgetScopedInstances();

        $this->recalculate(['--achievement' => $achievement->id])
            ->expectsOutputToContain('no se han podido leer')
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function recalculate(array $options = []): PendingCommand
    {
        $command = $this->artisan('achievements:recalculate', $options);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function ledger(): AchievementFactRepositoryInterface
    {
        return $this->app->make(AchievementFactRepositoryInterface::class);
    }
}
