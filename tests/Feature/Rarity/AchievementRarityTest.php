<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Rarity;

use App\Application\Services\TimezoneServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Infrastructure\Rarity\CachedAchievementRarity;
use Modules\Achievements\Jobs\RefreshAchievementRarityJob;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Rarity is the share of the people active in the last twelve months who hold an achievement:
 * accounts with a fact in that time, neither deactivated nor anonymized, and only accounts that still
 * exist.
 */
final class AchievementRarityTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsShowcase;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->withoutStarterCatalogue();
        $this->travelTo('2026-11-22 10:00:00');
    }

    public function test_the_share_counts_only_active_holders_among_active_people(): void
    {
        $achievement = (string) $this->achievement($this->countRule())->id;
        $ana = $this->person('Ana');
        $luis = $this->person('Luis');
        $marta = $this->person('Marta');
        $this->factAt($ana, '2026-10-01 18:00:00');
        $this->factAt($luis, '2026-09-01 18:00:00');
        $this->factAt($marta, '2026-08-01 18:00:00');
        $this->factAt($marta, '2026-08-02 18:00:00');
        $this->grantAt($ana, $achievement);
        // Holds it but played nothing in the last twelve months: neither counted nor holding
        $old = $this->person('Pedro');
        $this->factAt($old, '2025-11-21 18:00:00');
        $this->grantAt($old, $achievement);

        $rarity = app(AchievementRarityInterface::class)->refresh();

        $this->assertSame(3, $rarity->activePeople);
        $this->assertEqualsWithDelta(33.33, $rarity->shareOf($achievement), 0.01);
    }

    public function test_twelve_months_back_from_the_display_day_counts(): void
    {
        $person = $this->person('Ana');
        $this->factAt($person, '2025-11-22 00:00:00');

        $this->assertSame(1, app(AchievementRarityInterface::class)->refresh()->activePeople);
    }

    public function test_deactivated_anonymized_and_vanished_accounts_never_count(): void
    {
        $achievement = (string) $this->achievement($this->countRule())->id;
        $active = $this->person('Ana');
        $this->factAt($active, '2026-10-01 18:00:00');
        $deactivated = $this->person('Luis');
        $this->factAt($deactivated, '2026-10-01 18:00:00');
        $this->grantAt($deactivated, $achievement);
        $deactivated->delete();
        $anonymized = UserModel::factory()->create(['anonymized_at' => now()]);
        $this->factAt($anonymized, '2026-10-01 18:00:00');
        $this->grantAt($anonymized, $achievement);
        // The facts of an account deleted for good stay without a foreign key
        $vanished = '0195f3a2-7c1e-7d4b-9a8e-2f6c1b3d4e99';
        $this->factAt($vanished, '2026-10-01 18:00:00');
        $this->grantAt($vanished, $achievement);

        $rarity = app(AchievementRarityInterface::class)->refresh();

        $this->assertSame(1, $rarity->activePeople);
        $this->assertSame(0.0, $rarity->shareOf($achievement));
    }

    public function test_with_nobody_active_there_is_no_rarity(): void
    {
        $achievement = (string) $this->achievement($this->countRule())->id;

        $this->assertNull(app(AchievementRarityInterface::class)->refresh()->shareOf($achievement));
    }

    public function test_it_is_kept_in_the_cache_and_read_from_there(): void
    {
        $achievement = (string) $this->achievement($this->countRule())->id;
        $ana = $this->person('Ana');
        $this->factAt($ana, '2026-10-01 18:00:00');
        $this->grantAt($ana, $achievement);
        $this->refreshRarity();
        // A holder added behind its back is not seen until it is computed again
        $luis = $this->person('Luis');
        $this->factAt($luis, '2026-10-01 18:00:00');
        $this->app->forgetScopedInstances();

        $this->assertSame(100.0, app(AchievementRarityInterface::class)->current()->shareOf($achievement));
        $this->assertIsArray(Cache::get(CachedAchievementRarity::CACHE_KEY));

        Cache::forget(CachedAchievementRarity::CACHE_KEY);
        $this->app->forgetScopedInstances();

        $this->assertSame(50.0, app(AchievementRarityInterface::class)->current()->shareOf($achievement));
    }

    public function test_a_grant_computes_it_again(): void
    {
        $achievement = (string) $this->achievement(['version' => 1, 'metric' => 'manual'])->id;
        $ana = $this->person('Ana');
        $this->factAt($ana, '2026-10-01 18:00:00');
        $this->refreshRarity();

        app(AchievementGranterInterface::class)->grant(new NewGrant((string) $ana->id, $achievement, GrantSource::Manual, true));
        $this->app->forgetScopedInstances();

        $this->assertSame(100.0, app(AchievementRarityInterface::class)->current()->shareOf($achievement));
    }

    public function test_grants_queue_one_refresh_while_it_waits(): void
    {
        Bus::fake([RefreshAchievementRarityJob::class]);
        $achievement = (string) $this->achievement(['version' => 1, 'metric' => 'manual'])->id;

        foreach (['Ana', 'Luis', 'Marta'] as $name) {
            app(AchievementGranterInterface::class)->grant(new NewGrant((string) $this->person($name)->id, $achievement, GrantSource::Manual, true));
        }

        Bus::assertDispatchedTimes(RefreshAchievementRarityJob::class, 1);
    }

    public function test_it_is_computed_every_night(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(
            static fn ($event): bool => $event->description === 'achievements:rarity',
        );

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('30 3 * * *', $event->expression);
        $zone = $event->timezone;
        $this->assertSame(app(TimezoneServiceInterface::class)->displayTimezone()->getName(), $zone instanceof DateTimeZone ? $zone->getName() : $zone);
    }

    public function test_a_cache_that_fails_still_answers_from_the_database(): void
    {
        $achievement = (string) $this->achievement($this->countRule())->id;
        $ana = $this->person('Ana');
        $this->factAt($ana, '2026-10-01 18:00:00');
        $this->grantAt($ana, $achievement);
        $cache = $this->createStub(Repository::class);
        $cache->method('get')->willThrowException(new RuntimeException('down'));
        $cache->method('put')->willThrowException(new RuntimeException('down'));
        $this->app->forgetScopedInstances();
        $this->app->bind(Repository::class, static fn (): Repository => $cache);

        $this->assertSame(100.0, app(AchievementRarityInterface::class)->current()->shareOf($achievement));
    }

    public function test_the_active_people_query_has_an_index_of_its_own(): void
    {
        $indexes = array_column(Schema::getIndexes('achievements_facts'), 'columns', 'name');

        $this->assertSame(['occurred_at', 'user_id'], $indexes['achievements_facts_occurred_at_index'] ?? null);
        $this->assertTrue(DB::table('achievements_facts')->doesntExist());
    }
}
