<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Profiles;

use App\Domain\Events\UserAnonymized;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Modules\Achievements\Application\Services\CommunityMetricsInterface;
use Modules\Achievements\Domain\Events\AchievementFactsChanged;
use Modules\Achievements\Domain\Repositories\AchievementFactRepositoryInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Domain\ValueObjects\RecordedFact;
use Modules\Achievements\Infrastructure\Community\LedgerCommunityMetrics;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Modules\Achievements\Tests\Support\FailingCommunityLedger;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The owner's progress in a community achievement: a number only, from aggregate queries kept in
 * the cache, and nothing at all (never a misleading 0) when it cannot be read.
 */
final class CommunityProgressTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use BuildsShowcase;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->withoutStarterCatalogue();
        $this->registerInMemorySource();
        $this->app->forgetScopedInstances();
        Bus::fake([EvaluateAchievementsJob::class]);
        $this->travelTo('2026-11-22 10:00:00');
        $this->ana = $this->person('Ana');
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();

        parent::tearDown();
    }

    public function test_the_owner_sees_how_many_people_and_never_who(): void
    {
        $this->achievement(['version' => 1, 'metric' => 'people', 'kind' => 'game_session', 'filters' => ['role' => 'player', 'with_role' => 'player'], 'threshold' => 10], ['name' => 'Buena compañía']);
        $luis = $this->person('Luis Secreto');
        $marta = $this->person('Marta Escondida');
        $this->playedTogether('s1', [(string) $this->ana->id, (string) $luis->id, (string) $marta->id]);

        $props = $this->achievementsSection($this->render($this->ana, $this->ana))?->props;

        $this->assertNotNull($props);
        $this->assertSame(['value' => 2, 'threshold' => 10], $props['locked'][0]['progress']);
        $encoded = (string) json_encode($props);
        $this->assertStringNotContainsString((string) $luis->id, $encoded);
        $this->assertStringNotContainsString('Luis', $encoded);
        $this->assertStringNotContainsString('Marta', $encoded);
    }

    public function test_the_count_is_kept_in_the_cache_and_counted_again_when_the_facts_change(): void
    {
        $rule = AchievementRule::people('game_session', 10, 'player', 'player');
        $this->assertInstanceOf(AchievementRule::class, $rule);
        $luis = $this->person('Luis');
        $this->playedTogether('s1', [(string) $this->ana->id, (string) $luis->id]);
        $anaId = strtolower((string) $this->ana->id);

        $this->assertSame(1, $this->metrics()->progress($rule, $anaId));

        // A new game written straight to the ledger: the cached count stays until the facts change
        $marta = $this->person('Marta');
        $this->playedTogether('s2', [(string) $this->ana->id, (string) $marta->id]);
        $this->assertSame(1, $this->metrics()->progress($rule, $anaId));

        event(new AchievementFactsChanged([$anaId]));

        $this->assertSame(2, $this->metrics()->progress($rule, $anaId));
    }

    public function test_anonymizing_somebody_counts_again_the_people_who_shared_a_game_with_them(): void
    {
        $rule = AchievementRule::people('game_session', 10, 'player', 'player');
        $this->assertInstanceOf(AchievementRule::class, $rule);
        $luis = $this->person('Luis');
        $this->playedTogether('s1', [(string) $this->ana->id, (string) $luis->id]);
        $anaId = strtolower((string) $this->ana->id);
        $this->assertSame(1, $this->metrics()->progress($rule, $anaId));

        UserModel::query()->whereKey($luis->id)->update(['anonymized_at' => now()]);
        event(new UserAnonymized((string) $luis->id));

        $this->assertSame(0, $this->metrics()->progress($rule, $anaId));
    }

    public function test_a_ledger_that_cannot_be_read_shows_no_progress_rather_than_zero(): void
    {
        $log = Log::spy();
        $this->achievement(['version' => 1, 'metric' => 'mentor', 'threshold' => 1], ['name' => 'Mentor']);
        $this->achievement($this->countRule(threshold: 5), ['name' => 'Cinco partidas']);
        $this->playedTogether('s1', [(string) $this->ana->id]);
        $this->app->instance(AchievementFactRepositoryInterface::class, new FailingCommunityLedger($this->app->make(AchievementFactRepositoryInterface::class)));
        $this->app->forgetScopedInstances();

        $props = $this->achievementsSection($this->render($this->ana, $this->ana))?->props;

        $this->assertNotNull($props);
        $locked = array_column($props['locked'], null, 'name');
        $this->assertNull($locked['Mentor']['progress']);
        $this->assertSame(['value' => 1, 'threshold' => 5], $locked['Cinco partidas']['progress']);
        // The failure is not kept: once the ledger reads again, the count shows
        $this->assertNull($this->app->make('cache.store')->get(LedgerCommunityMetrics::CACHE_PREFIX.strtolower((string) $this->ana->id)));
        $log->shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => ($context['reason'] ?? null) === 'community_unavailable' && ! str_contains((string) json_encode($context), 'Ana'));
    }

    /**
     * @param  list<string>  $players
     */
    private function playedTogether(string $subject, array $players): void
    {
        $this->ledgerSubject(InMemoryActivitySource::TYPE, $subject, 'game_session', array_map(
            fn (string $player): RecordedFact => $this->recordedFact($player, when: '2026-10-01 18:00:00'),
            $players,
        ));
    }

    private function metrics(): CommunityMetricsInterface
    {
        return $this->app->make(CommunityMetricsInterface::class);
    }
}
