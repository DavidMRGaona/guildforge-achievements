<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Recognitions;

use App\Application\Profiles\DTOs\ResolvedProfileDTO;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Enums\AchievementStatus;
use Modules\Achievements\Application\Services\AchievementEvaluatorInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Application\Services\AchievementStatusRule;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Domain\Rules\AchievementRule;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Jobs\EvaluateRecognitionAchievementsJob;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsRecognitions;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * «Reconocimientos recibidos» in achievement rules (D4): counted with the same monthly cut as the
 * totals others see, so the achievement never tells when one arrived.
 */
final class RecognitionAchievementsTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use BuildsRecognitions;
    use BuildsShowcase;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    private UserModel $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        config(['app.display_timezone' => 'Europe/Madrid']);
        $this->withoutStarterCatalogue();
        $this->registerInMemorySource();
        Bus::fake([EvaluateAchievementsJob::class]);
        $this->switchRecognitions();
        $this->travelTo(new DateTimeImmutable('2026-12-10 12:00:00', new DateTimeZone('UTC')));

        $this->ana = $this->person('Ana');
        $this->bob = $this->person('Bob');
        $this->played('mesa-1', '2026-12-09 18:00:00', $this->ana, $this->bob);
        $this->recognitions()->give($this->ana, InMemoryActivitySource::TYPE, 'mesa-1', $this->handleOf($this->bob), $this->typeId());
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();

        parent::tearDown();
    }

    public function test_the_rule_reads_back_what_it_stores_and_refuses_anything_else(): void
    {
        $any = AchievementRule::recognitions(3);
        $ofType = AchievementRule::recognitions(5, $this->typeId());

        $this->assertSame(['version' => 1, 'metric' => 'recognitions', 'threshold' => 3], $any?->toArray());
        $this->assertSame($this->typeId(), AchievementRule::tryFrom($ofType?->toArray())?->recognitionType);
        $this->assertNull(AchievementRule::tryFrom(['version' => 1, 'metric' => 'recognitions', 'threshold' => 3, 'filters' => ['type' => 'gran-narracion']]));
        $this->assertNull(AchievementRule::tryFrom(['version' => 1, 'metric' => 'recognitions', 'threshold' => 3, 'window' => ['type' => 'season']]));
        $this->assertNull(AchievementRule::tryFrom(['version' => 1, 'metric' => 'recognitions', 'threshold' => 3, 'kind' => 'game_session']));
    }

    public function test_it_waits_for_the_first_of_the_month(): void
    {
        $achievement = $this->achievement(AchievementRule::recognitions(1)?->toArray() ?? [], ['name' => 'Primer reconocimiento']);

        $this->evaluator()->evaluate([(string) $this->bob->id], silent: false);
        $this->assertFalse($this->holds($this->bob, $achievement->id));

        $this->travelTo(new DateTimeImmutable('2026-12-31 23:00:00', new DateTimeZone('UTC')));
        $result = $this->evaluator()->evaluate([(string) $this->bob->id], silent: false);

        $this->assertTrue($this->holds($this->bob, $achievement->id));
        // Counted on the 1st, so it is news then, not a month-old grant
        $this->assertFalse($result->granted[0]->silent);
    }

    public function test_a_type_counts_only_its_own(): void
    {
        $companion = $this->achievement(AchievementRule::recognitions(1, $this->typeId('good_companion'))?->toArray() ?? [], ['name' => 'Buen compañero']);
        $storytelling = $this->achievement(AchievementRule::recognitions(1, $this->typeId())?->toArray() ?? [], ['name' => 'Gran narrador']);
        $this->travelTo(new DateTimeImmutable('2027-01-02 12:00:00', new DateTimeZone('UTC')));

        $this->evaluator()->evaluate([(string) $this->bob->id], silent: false);

        $this->assertTrue($this->holds($this->bob, $storytelling->id));
        $this->assertFalse($this->holds($this->bob, $companion->id));
    }

    public function test_the_monthly_job_evaluates_everybody_who_received_some(): void
    {
        $achievement = $this->achievement(AchievementRule::recognitions(1)?->toArray() ?? [], ['name' => 'Primer reconocimiento']);
        $this->travelTo(new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')));

        $this->app->call([new EvaluateRecognitionAchievementsJob, 'handle']);

        Bus::assertDispatched(EvaluateAchievementsJob::class, static fn (EvaluateAchievementsJob $job): bool => $job->achievementIds === [$achievement->id] && ! $job->silent);

        $this->evaluator()->evaluateEverybody([$achievement->id], silent: false);
        $this->assertTrue($this->holds($this->bob, $achievement->id));
        $this->assertFalse($this->holds($this->ana, $achievement->id));
    }

    public function test_it_is_not_evaluated_while_recognitions_are_off(): void
    {
        $achievement = $this->achievement(AchievementRule::recognitions(1)?->toArray() ?? [], ['name' => 'Primer reconocimiento']);
        $this->switchRecognitions(false);
        $entity = $this->app->make(AchievementRepositoryInterface::class)->find($achievement->id);
        $this->assertNotNull($entity);

        $status = $this->app->make(AchievementStatusRule::class)->status($entity, $this->app->make(AchievementsSettingsInterface::class)->settings(), []);

        $this->assertSame(AchievementStatus::RecognitionsOff, $status);
    }

    public function test_the_owner_progress_counts_as_the_rule_will(): void
    {
        config(['app.locale' => 'es']);
        $this->achievement(AchievementRule::recognitions(2)?->toArray() ?? [], ['name' => 'Dos reconocimientos']);
        $this->app->make(AchievementsSettingsInterface::class)->save(new AchievementsSettings(progress: true, recognitions: true));

        $locked = $this->render($this->bob, $this->bob);
        $progress = $this->progressOf($locked, 'Dos reconocimientos');
        $this->assertSame(0, $progress);

        $this->travelTo(new DateTimeImmutable('2027-01-02 12:00:00', new DateTimeZone('UTC')));
        $this->assertSame(1, $this->progressOf($this->render($this->bob, $this->bob), 'Dos reconocimientos'));
    }

    private function evaluator(): AchievementEvaluatorInterface
    {
        $this->app->forgetScopedInstances();

        return $this->app->make(AchievementEvaluatorInterface::class);
    }

    private function holds(UserModel $user, string $achievementId): bool
    {
        return DB::table('achievements_grants')->where('user_id', strtolower((string) $user->id))->where('achievement_id', $achievementId)->exists();
    }

    private function progressOf(ResolvedProfileDTO $profile, string $name): ?int
    {
        /** @var list<array{name: string, progress: array{value: int}|null}> $locked */
        $locked = $this->achievementsSection($profile)?->props['locked'] ?? [];

        foreach ($locked as $item) {
            if ($item['name'] === $name) {
                return $item['progress']['value'] ?? null;
            }
        }

        return null;
    }
}
