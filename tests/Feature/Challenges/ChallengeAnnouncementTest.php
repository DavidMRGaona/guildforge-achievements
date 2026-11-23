<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Challenges;

use App\Application\SiteNotifications\DTOs\SiteNotificationItemDTO;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Bus;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Jobs\EvaluateChallengesJob;
use Modules\Achievements\Jobs\ReleaseAchievementAnnouncementsJob;
use Modules\Achievements\Notifications\AchievementNoticeCategories;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsChallenges;
use Modules\Achievements\Tests\Support\BuildsNotices;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Challenge rewards go through the notices of achievements#6 like any other grant: the owner hears
 * of it in the bell, and others in the monthly summary, under the month the reward was granted,
 * which is when the completion (or the person's contribution) was found: never an earlier month,
 * whatever month the facts were in. A reward found by a silent count is granted, and told, by the
 * next count with notices.
 */
final class ChallengeAnnouncementTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use BuildsChallenges;
    use BuildsNotices;
    use BuildsShowcase;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private const string SESSIONS = InMemoryActivitySource::TYPE;

    private UserModel $ana;

    private UserModel $bea;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        app()->setLocale('es');
        $this->withoutStarterCatalogue();
        $this->registerInMemorySource();
        Bus::fake([EvaluateAchievementsJob::class, EvaluateChallengesJob::class]);
        $this->switchChallenges();
        $this->travelTo('2026-12-20 12:00:00');
        $this->ana = $this->person('Ana');
        $this->bea = $this->person('Bea');
        $this->allowAnnouncing($this->ana);
    }

    protected function tearDown(): void
    {
        InMemoryActivitySource::reset();

        parent::tearDown();
    }

    public function test_the_owner_hears_at_once_and_others_in_the_monthly_summary(): void
    {
        $this->played($this->ana, '2026-12-10 18:00:00');
        $this->challenge(['target' => 1], ['reward_achievement_id' => $this->reward(['name' => 'Diciembre en la mesa'])->id]);

        $this->challenges()->evaluate(null, silent: false);

        $this->assertCount(1, $this->bellOf($this->ana, AchievementNoticeCategories::EARNED));
        $this->assertSame([], $this->announcementsOf($this->bea));

        $this->release();

        $items = $this->announcementsOf($this->bea);
        $this->assertCount(1, $items);
        $this->assertSame('Ana consiguió un logro de un reto en diciembre', $items[0]->title);
        $this->assertSame('Ana: «Diciembre en la mesa»', $items[0]->body);
    }

    public function test_a_completion_found_later_is_told_under_the_month_it_was_found(): void
    {
        // A November challenge, first counted in December (its late facts' days)
        $this->played($this->ana, '2026-11-10 18:00:00');
        $this->challenge(['target' => 1], ['starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);

        $this->challenges()->evaluate(null, silent: false);
        $this->release();

        $this->assertSame('Ana consiguió un logro de un reto en diciembre', $this->announcementsOf($this->bea)[0]->title ?? null);
    }

    public function test_a_reward_found_by_a_silent_count_goes_the_same_way_with_the_next_count(): void
    {
        $this->played($this->ana, '2026-12-10 18:00:00');
        $this->challenge(['target' => 1]);

        $this->challenges()->evaluate(null, silent: true);
        $afterSilent = $this->bellOf($this->ana, AchievementNoticeCategories::EARNED);
        $this->assertSame([], $afterSilent);

        $this->challenges()->evaluate(null, silent: false);
        $afterNotices = $this->bellOf($this->ana, AchievementNoticeCategories::EARNED);
        $this->assertCount(1, $afterNotices);

        $this->release();
        $this->assertCount(1, $this->announcementsOf($this->bea));
    }

    private function played(UserModel $user, string $when): void
    {
        $this->ledgerSubject(self::SESSIONS, 'table-'.uniqid(), 'game_session', [$this->recordedFact(strtolower((string) $user->id), when: $when)]);
    }

    private function release(): void
    {
        $this->travelTo(now()->startOfMonth()->addMonthNoOverflow()->setTime(10, 0));
        $this->app->forgetScopedInstances();
        app()->call([new ReleaseAchievementAnnouncementsJob, 'handle']);
    }

    /**
     * @return list<SiteNotificationItemDTO>
     */
    private function announcementsOf(UserModel $user): array
    {
        return $this->bellOf($user, AchievementNoticeCategories::ANNOUNCEMENTS);
    }
}
