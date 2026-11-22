<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Grants;

use App\Domain\Events\UserAnonymized;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Events\AchievementFactsChanged;
use Modules\Achievements\Domain\Events\AchievementRevoked;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Listeners\ForgetAnonymizedAccountGrants;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The race between a revoke and a grant by a rule, a challenge or in bulk is closed by the order of
 * the locks, not tested here with two connections: ModuleTestCase enables the module (migrations and
 * its seeder) inside the wrapping transaction of every test, so a test without that transaction
 * (`$connectionsToTransact = []`) would leave the module's tables and starter catalogue committed
 * for the next ones. revoke() and deleteForUser() take the account's row FOR UPDATE before the grant;
 * grant() holds it with a shared lock while it reads the history and writes. Whichever comes second
 * waits for the other's commit: a grant then finds the revoke in the history, a revoke finds the grant.
 */
final class GrantLogTest extends ModuleTestCase
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
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();

        parent::tearDown();
    }

    public function test_a_manual_grant_is_logged_with_its_actor_and_reason_in_the_same_write(): void
    {
        $ana = $this->account(self::ANA);
        $actor = $this->account();
        $achievement = $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Fundadora']);

        $grant = $this->granter()->grant(new NewGrant($ana, $achievement->id, GrantSource::Manual, silent: false, actorId: $actor, reason: 'Fundó la asociación'));

        $this->assertInstanceOf(AchievementGrant::class, $grant);
        $row = DB::table('achievements_grant_log')->sole();
        $this->assertSame($ana, $row->user_id);
        $this->assertSame($actor, $row->actor_id);
        $this->assertSame($achievement->id, $row->achievement_id);
        $this->assertSame('Fundadora', $row->achievement_name);
        $this->assertSame('granted', $row->action);
        $this->assertSame('manual', $row->source);
        $this->assertSame('Fundó la asociación', $row->reason);
    }

    public function test_a_grant_already_held_writes_nothing_to_the_log(): void
    {
        $ana = $this->account(self::ANA);
        $achievement = $this->achievement(['version' => 1, 'metric' => 'manual']);
        $this->granter()->grant(new NewGrant($ana, $achievement->id, GrantSource::Manual, silent: true, actorId: $this->account()));

        $this->assertNull($this->granter()->grant(new NewGrant($ana, $achievement->id, GrantSource::Manual, silent: true, actorId: $this->account())));

        $this->assertSame(1, DB::table('achievements_grant_log')->count());
    }

    public function test_a_rule_grant_is_logged_without_an_actor(): void
    {
        $ana = $this->account(self::ANA);
        $achievement = $this->achievement($this->countRule());
        $this->ledgerSubject(InMemoryActivitySource::TYPE, '42', 'game_session', [$this->recordedFact($ana)]);

        event(new AchievementFactsChanged([$ana], silent: true));

        $row = DB::table('achievements_grant_log')->where('achievement_id', $achievement->id)->sole();
        $this->assertSame('rule', $row->source);
        $this->assertSame('granted', $row->action);
        $this->assertNull($row->actor_id);
        $this->assertNull($row->reason);
    }

    public function test_revoking_deletes_the_grant_and_logs_who_and_why(): void
    {
        Event::fake([AchievementRevoked::class]);
        $ana = $this->account(self::ANA);
        $actor = $this->account();
        $achievement = $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Fundadora']);
        $grant = $this->granter()->grant(new NewGrant($ana, $achievement->id, GrantSource::Manual, silent: true, actorId: $actor));
        $this->assertInstanceOf(AchievementGrant::class, $grant);

        $revoked = $this->granter()->revoke($grant->id, $actor, 'Concedido por error');

        $this->assertInstanceOf(AchievementGrant::class, $revoked);
        $this->assertSame(0, DB::table('achievements_grants')->count());
        $row = DB::table('achievements_grant_log')->where('action', 'revoked')->sole();
        $this->assertSame($ana, $row->user_id);
        $this->assertSame($actor, $row->actor_id);
        $this->assertSame('manual', $row->source);
        $this->assertSame('Concedido por error', $row->reason);
        $this->assertSame('Fundadora', $row->achievement_name);
        Event::assertDispatched(AchievementRevoked::class, static fn (AchievementRevoked $event): bool => $event->grantId === $grant->id
            && $event->userId === $ana
            && $event->achievementId === $achievement->id);
    }

    public function test_revoking_a_grant_that_is_gone_does_nothing(): void
    {
        Event::fake([AchievementRevoked::class]);

        $this->assertNull($this->granter()->revoke('0195f3a2-7c1e-7d4b-9a8e-000000000000', $this->account(), 'Motivo'));
        $this->assertNull($this->granter()->revoke('not-an-id', $this->account(), 'Motivo'));

        $this->assertSame(0, DB::table('achievements_grant_log')->count());
        Event::assertNotDispatched(AchievementRevoked::class);
    }

    public function test_a_revoke_needs_a_reason(): void
    {
        $ana = $this->account(self::ANA);
        $achievement = $this->achievement(['version' => 1, 'metric' => 'manual']);
        $grant = $this->granter()->grant(new NewGrant($ana, $achievement->id, GrantSource::Manual, silent: true));
        $this->assertInstanceOf(AchievementGrant::class, $grant);

        $this->assertNull($this->granter()->revoke($grant->id, $this->account(), '   '));

        $this->assertSame(1, DB::table('achievements_grants')->count());
    }

    public function test_a_revoked_achievement_never_comes_back_by_itself_but_can_be_granted_by_hand(): void
    {
        $ana = $this->account(self::ANA);
        $achievement = $this->achievement($this->countRule());
        $this->ledgerSubject(InMemoryActivitySource::TYPE, '42', 'game_session', [$this->recordedFact($ana)]);
        event(new AchievementFactsChanged([$ana], silent: true));
        $grantId = (string) DB::table('achievements_grants')->where('user_id', $ana)->value('id');
        $this->granter()->revoke($grantId, $this->account(), 'Partida falsa');

        event(new AchievementFactsChanged([$ana], silent: true));
        $this->assertSame(0, DB::table('achievements_grants')->count());
        $this->assertNull($this->granter()->grant(new NewGrant($ana, $achievement->id, GrantSource::Bulk, silent: true, actorId: $this->account())));

        $this->assertNotNull($this->granter()->grant(new NewGrant($ana, $achievement->id, GrantSource::Manual, silent: true, actorId: $this->account())));
        // Granted by hand again, a later revoke-free rule run keeps it
        event(new AchievementFactsChanged([$ana], silent: true));
        $this->assertSame(1, DB::table('achievements_grants')->count());
    }

    public function test_anonymizing_deletes_the_grants_and_keeps_the_history_without_the_person(): void
    {
        $ana = $this->account(self::ANA);
        $actor = $this->account();
        $achievement = $this->achievement(['version' => 1, 'metric' => 'manual']);
        $grant = $this->granter()->grant(new NewGrant($ana, $achievement->id, GrantSource::Manual, silent: true, actorId: $actor, reason: 'Por Ana'));
        $this->assertInstanceOf(AchievementGrant::class, $grant);
        $this->granter()->revoke($grant->id, $actor, 'Ana lo pidió');
        // What Ana did to someone else stays hers to have done
        $luis = $this->account(self::LUIS);
        $this->granter()->grant(new NewGrant($luis, $achievement->id, GrantSource::Manual, silent: true, actorId: $ana, reason: 'Ayudó'));
        $this->granter()->grant(new NewGrant($ana, $achievement->id, GrantSource::Manual, silent: true, actorId: $actor));
        DB::table('users')->where('id', $ana)->update(['anonymized_at' => now()]);

        $this->app->make(ForgetAnonymizedAccountGrants::class)->handle(new UserAnonymized($ana));

        $this->assertSame(0, DB::table('achievements_grants')->where('user_id', $ana)->count());
        $this->assertSame(0, DB::table('achievements_grant_log')->where('user_id', $ana)->count());
        $forgotten = DB::table('achievements_grant_log')->whereNull('user_id')->get();
        $this->assertCount(3, $forgotten);
        $this->assertSame([null], $forgotten->pluck('reason')->unique()->values()->all());
        $byAna = DB::table('achievements_grant_log')->where('actor_id', $ana)->sole();
        $this->assertSame($luis, $byAna->user_id);
        $this->assertSame('Ayudó', $byAna->reason);
    }

    public function test_deleting_an_achievement_keeps_its_history_with_its_name(): void
    {
        $ana = $this->account(self::ANA);
        $actor = $this->account();
        $achievement = $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Efímero']);
        $grant = $this->granter()->grant(new NewGrant($ana, $achievement->id, GrantSource::Manual, silent: true, actorId: $actor));
        $this->assertInstanceOf(AchievementGrant::class, $grant);
        $this->granter()->revoke($grant->id, $actor, 'Ya no existe');

        $achievement->delete();

        $rows = DB::table('achievements_grant_log')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([null], $rows->pluck('achievement_id')->unique()->values()->all());
        $this->assertSame(['Efímero'], $rows->pluck('achievement_name')->unique()->values()->all());
    }

    private function granter(): AchievementGranterInterface
    {
        return $this->app->make(AchievementGranterInterface::class);
    }
}
