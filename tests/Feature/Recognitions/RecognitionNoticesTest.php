<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Recognitions;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Bus;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Jobs\SendRecognitionPromptsJob;
use Modules\Achievements\Notifications\AchievementNoticeCategories;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsNotices;
use Modules\Achievements\Tests\Support\BuildsRecognitions;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The notices of recognitions (D5): the receiver hears who gave it, never the game; the invitation
 * reaches only those who played, once per game, titled as the source describes it to them;
 * nobody else hears of either.
 */
final class RecognitionNoticesTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use BuildsNotices;
    use BuildsRecognitions;
    use BuildsShowcase;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    private UserModel $bob;

    private UserModel $marta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        config(['app.display_timezone' => 'Europe/Madrid']);
        $this->registerInMemorySource();
        Bus::fake([EvaluateAchievementsJob::class]);
        $this->switchRecognitions();
        $this->travelTo(new DateTimeImmutable('2026-12-10 12:00:00', new DateTimeZone('UTC')));

        $this->ana = $this->person('Ana');
        $this->bob = $this->person('Bob');
        $this->marta = $this->person('Marta');
        $this->played('mesa-1', '2026-12-09 18:00:00', $this->ana, $this->bob);
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();

        parent::tearDown();
    }

    public function test_the_receiver_is_told_who_gave_it_and_nobody_else_hears(): void
    {
        $this->recognitions()->give($this->ana, InMemoryActivitySource::TYPE, 'mesa-1', $this->handleOf($this->bob), $this->typeId());

        $notices = $this->bellOf($this->bob, AchievementNoticeCategories::RECOGNITIONS);
        $this->assertCount(1, $notices);
        $this->assertSame('Ana te ha reconocido: «Gran narración»', $notices[0]->title);
        $this->assertStringNotContainsString('mesa-1', $notices[0]->title.' '.$notices[0]->body);
        $this->assertSame('/logros/reconocimientos', $notices[0]->url);

        $this->assertSame([], $this->bellOf($this->ana, AchievementNoticeCategories::RECOGNITIONS));
        $this->assertSame([], $this->bellOf($this->marta, AchievementNoticeCategories::RECOGNITIONS));
    }

    public function test_whoever_turned_the_category_off_hears_nothing(): void
    {
        $this->turnOff($this->bob, AchievementNoticeCategories::RECOGNITIONS);

        $this->recognitions()->give($this->ana, InMemoryActivitySource::TYPE, 'mesa-1', $this->handleOf($this->bob), $this->typeId());

        $this->assertSame([], $this->bellOf($this->bob, AchievementNoticeCategories::RECOGNITIONS));
    }

    public function test_the_invitation_reaches_those_who_played_once_per_game(): void
    {
        $this->app->call([new SendRecognitionPromptsJob, 'handle']);

        foreach ([$this->ana, $this->bob] as $player) {
            $notices = $this->bellOf($player, AchievementNoticeCategories::RECOGNITIONS);
            $this->assertCount(1, $notices);
            $this->assertSame('¿Quieres reconocer a alguien de «Partida mesa-1»?', $notices[0]->title);
        }

        $this->assertSame([], $this->bellOf($this->marta, AchievementNoticeCategories::RECOGNITIONS));

        // The next day it is not asked again
        $this->travelTo(new DateTimeImmutable('2026-12-11 12:00:00', new DateTimeZone('UTC')));
        $this->app->call([new SendRecognitionPromptsJob, 'handle']);
        $notices = $this->bellOf($this->ana, AchievementNoticeCategories::RECOGNITIONS);
        $this->assertCount(1, $notices);
        $this->assertSame(1, $notices[0]->count);
    }

    public function test_nobody_is_invited_with_nobody_to_recognise(): void
    {
        $this->choose($this->bob, hidden: true);

        $this->app->call([new SendRecognitionPromptsJob, 'handle']);

        $this->assertSame([], $this->bellOf($this->ana, AchievementNoticeCategories::RECOGNITIONS));
    }

    public function test_nobody_is_invited_while_recognitions_are_off(): void
    {
        $this->switchRecognitions(false);

        $this->app->call([new SendRecognitionPromptsJob, 'handle']);

        $this->assertSame([], $this->bellOf($this->ana, AchievementNoticeCategories::RECOGNITIONS));
    }
}
