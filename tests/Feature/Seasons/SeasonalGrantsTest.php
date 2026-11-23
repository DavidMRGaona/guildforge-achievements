<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Seasons;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\DTOs\GrantedAchievementDTO;
use Modules\Achievements\Application\Enums\AchievementStatus;
use Modules\Achievements\Application\Services\AchievementEvaluatorInterface;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Entities\AchievementGrant;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Events\AchievementGranted;
use Modules\Achievements\Domain\Repositories\AchievementGrantRepositoryInterface;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Domain\ValueObjects\RecordedFact;
use Modules\Achievements\Filament\Support\AchievementPanelView;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Repeatable achievements and streaks, granted from the ledger with facts stored in UTC and seasons
 * and months counted in Madrid, the display zone.
 */
final class SeasonalGrantsTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private const string SESSIONS = InMemoryActivitySource::TYPE;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        config(['app.display_timezone' => 'Europe/Madrid']);
        $this->withoutStarterCatalogue();
        $this->registerInMemorySource();
        Bus::fake([EvaluateAchievementsJob::class]);
        Event::fake([AchievementGranted::class]);
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();

        parent::tearDown();
    }

    public function test_a_season_rule_is_granted_once_in_each_season_it_was_met(): void
    {
        $this->travelTo(new DateTimeImmutable('2027-03-01 12:00:00', new DateTimeZone('UTC')));
        $ana = $this->account(self::ANA);
        $twoGames = $this->achievement($this->countRule(threshold: 2, extra: ['window' => ['type' => 'season']]));
        // Two in 2025, one in 2026, and the last one at 00:30 on 1 January 2027 in Madrid
        $this->playedAt($ana, 's1', '2025-04-01 18:00:00');
        $this->playedAt($ana, 's2', '2025-05-01 18:00:00');
        $this->playedAt($ana, 's3', '2026-12-31 22:30:00');
        $this->playedAt($ana, 's4', '2026-12-31 23:30:00');

        $result = $this->evaluator()->evaluate([$ana], silent: true);

        $this->assertEquals([new GrantedAchievementDTO($ana, $twoGames->id, true, '2025')], $result->granted);

        $this->playedAt($ana, 's5', '2027-02-20 18:00:00');
        $result = $this->evaluator()->evaluate([$ana], silent: false);

        $this->assertEquals([new GrantedAchievementDTO($ana, $twoGames->id, false, '2027')], $result->granted);
        $this->assertSame(['2025', '2027'], $this->seasonsOf($ana));
        Event::assertDispatched(AchievementGranted::class, static fn (AchievementGranted $event): bool => $event->season === '2027' && ! $event->silent);

        // Evaluating again grants nothing new
        $this->assertSame([], $this->evaluator()->evaluate([$ana], silent: true)->granted);
    }

    public function test_a_streak_is_granted_once_without_a_season(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC')));
        $ana = $this->account(self::ANA);
        $streak = $this->achievement($this->countRule(extra: ['window' => ['type' => 'streak', 'months' => 3]]));
        // 31 March at 22:30 UTC is April in Madrid: February, March (the 10th) and April in a row
        $this->playedAt($ana, 's1', '2026-02-10 18:00:00');
        $this->playedAt($ana, 's2', '2026-03-10 18:00:00');
        $this->playedAt($ana, 's3', '2026-03-31 22:30:00');

        $result = $this->evaluator()->evaluate([$ana], silent: true);

        $this->assertEquals([new GrantedAchievementDTO($ana, $streak->id, true)], $result->granted);
        $this->assertSame([''], $this->seasonsOf($ana));
    }

    public function test_a_streak_per_season_is_granted_in_each_season(): void
    {
        $this->travelTo(new DateTimeImmutable('2027-04-01 12:00:00', new DateTimeZone('UTC')));
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule(extra: ['window' => ['type' => 'streak', 'months' => 2, 'per_season' => true]]));
        $this->playedAt($ana, 's1', '2026-05-10 18:00:00');
        $this->playedAt($ana, 's2', '2026-06-10 18:00:00');
        // December and January are in a row, but in two seasons
        $this->playedAt($ana, 's3', '2026-12-10 18:00:00');
        $this->playedAt($ana, 's4', '2027-01-10 18:00:00');

        $this->evaluator()->evaluate([$ana], silent: true);
        $this->assertSame(['2026'], $this->seasonsOf($ana));

        $this->playedAt($ana, 's5', '2027-02-10 18:00:00');
        $this->evaluator()->evaluate([$ana], silent: true);
        $this->assertSame(['2026', '2027'], $this->seasonsOf($ana));
    }

    public function test_a_season_taken_away_is_not_granted_again_while_other_seasons_are(): void
    {
        $this->travelTo(new DateTimeImmutable('2027-03-01 12:00:00', new DateTimeZone('UTC')));
        $ana = $this->account(self::ANA);
        $admin = $this->account();
        $this->achievement($this->countRule(extra: ['window' => ['type' => 'season']]));
        $this->playedAt($ana, 's1', '2026-04-01 18:00:00');
        $this->evaluator()->evaluate([$ana], silent: true);
        $grant = $this->grants()->forUser($ana)[0];
        $this->app->make(AchievementGranterInterface::class)->revoke($grant->id, $admin, 'Error de registro');

        $this->playedAt($ana, 's2', '2027-02-01 18:00:00');
        $this->evaluator()->evaluate([$ana], silent: true);

        $this->assertSame(['2027'], $this->seasonsOf($ana));
    }

    public function test_a_grant_stays_when_the_facts_behind_it_go(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC')));
        $ana = $this->account(self::ANA);
        $this->achievement($this->countRule(extra: ['window' => ['type' => 'season']]));
        $this->playedAt($ana, 's1', '2026-04-01 18:00:00');
        $this->evaluator()->evaluate([$ana], silent: true);

        // The source publishes the subject again without the person
        $this->ledgerSubject(self::SESSIONS, 's1', 'game_session', []);
        $this->evaluator()->evaluate([$ana], silent: true);

        $this->assertSame(['2026'], $this->seasonsOf($ana));
    }

    public function test_seasons_and_streaks_switched_off_are_not_evaluated_and_keep_what_was_granted(): void
    {
        $this->travelTo(new DateTimeImmutable('2027-03-01 12:00:00', new DateTimeZone('UTC')));
        $ana = $this->account(self::ANA);
        $seasonal = $this->achievement($this->countRule(extra: ['window' => ['type' => 'season']]));
        $streak = $this->achievement($this->countRule(extra: ['window' => ['type' => 'streak', 'months' => 2]]));
        $ever = $this->achievement($this->countRule());
        $this->playedAt($ana, 's1', '2026-04-01 18:00:00');
        $this->evaluator()->evaluate([$ana], silent: true, achievementIds: [$seasonal->id]);

        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(seasonsAndStreaks: false));
        $this->playedAt($ana, 's2', '2026-05-01 18:00:00');
        $this->playedAt($ana, 's3', '2027-02-01 18:00:00');
        $result = $this->evaluator()->evaluate([$ana], silent: true);

        $this->assertEquals([new GrantedAchievementDTO($ana, $ever->id, true)], $result->granted);
        $this->assertSame(['', '2026'], $this->seasonsOf($ana));
        $this->assertSame(AchievementStatus::SeasonsOff, AchievementPanelView::status($streak));
        $this->assertSame(AchievementStatus::SeasonsOff, AchievementPanelView::status($seasonal));
    }

    public function test_a_manual_achievement_per_season_is_granted_by_hand_once_in_each_season(): void
    {
        $ana = $this->account(self::ANA);
        $admin = $this->account();
        $best = $this->achievement(['version' => 1, 'metric' => 'manual', 'window' => ['type' => 'season']]);
        $granter = $this->app->make(AchievementGranterInterface::class);

        $first = $granter->grant(new NewGrant($ana, $best->id, GrantSource::Manual, true, $admin, '2026'));
        $again = $granter->grant(new NewGrant($ana, $best->id, GrantSource::Manual, true, $admin, '2026'));
        $next = $granter->grant(new NewGrant($ana, $best->id, GrantSource::Manual, true, $admin, '2027'));

        $this->assertInstanceOf(AchievementGrant::class, $first);
        $this->assertNull($again);
        $this->assertInstanceOf(AchievementGrant::class, $next);
        $this->assertSame(['2026', '2027'], $this->seasonsOf($ana));
    }

    private function playedAt(string $userId, string $subject, string $utc): void
    {
        $this->ledgerSubject(self::SESSIONS, $subject, 'game_session', [
            new RecordedFact($userId, 'player', new DateTimeImmutable($utc, new DateTimeZone('UTC')), []),
        ]);
    }

    /**
     * @return list<string>
     */
    private function seasonsOf(string $userId): array
    {
        $seasons = array_map(static fn (AchievementGrant $grant): string => $grant->season ?? '', $this->grants()->forUser($userId));
        sort($seasons);

        return $seasons;
    }

    private function evaluator(): AchievementEvaluatorInterface
    {
        $this->app->forgetScopedInstances();

        return $this->app->make(AchievementEvaluatorInterface::class);
    }

    private function grants(): AchievementGrantRepositoryInterface
    {
        return $this->app->make(AchievementGrantRepositoryInterface::class);
    }
}
