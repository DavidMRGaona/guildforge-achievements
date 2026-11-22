<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Notices;

use App\Application\Authorization\Services\AssociationMembershipServiceInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Application\SiteNotifications\DTOs\SiteNotificationItemDTO;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserProfileModel;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Exceptions\AchievementSettingsUnreadableException;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Jobs\DeliverAchievementAnnouncementsJob;
use Modules\Achievements\Jobs\ReleaseAchievementAnnouncementsJob;
use Modules\Achievements\Notifications\AchievementNoticeCategories;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsNotices;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The monthly summary of others' rare and challenge achievements (achievements#6): on the first of
 * each month, one notice per reader with whoever they may see who allowed it, told only by month.
 * Secrets the reader does not hold are only counted.
 */
final class AchievementAnnouncementTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsNotices;
    use BuildsShowcase;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    private UserModel $bea;

    /** @var array<string, float> */
    private array $rareShares = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        app()->setLocale('es');
        $this->travelTo('2026-11-15 18:00:00');
        $this->withoutStarterCatalogue();
        $this->ana = $this->person('Ana');
        $this->bea = $this->person('Bea');
        $this->allowAnnouncing($this->ana);
    }

    public function test_a_rare_achievement_is_told_in_the_next_monthly_summary(): void
    {
        Mail::fake();

        $this->earn($this->ana, $this->rare('Maestro de campañas'));

        // The owner hears of it at once; others, not before the summary
        $this->assertCount(1, $this->bellOf($this->ana, AchievementNoticeCategories::EARNED));
        $this->assertSame([], $this->announcementsOf($this->bea));

        $this->release();

        $items = $this->announcementsOf($this->bea);
        $this->assertCount(1, $items);
        $this->assertSame('Ana consiguió logros raros en noviembre', $items[0]->title);
        $this->assertSame('Ana: «Maestro de campañas»', $items[0]->body);
        $this->assertSame('/miembros/'.$this->handleOf($this->ana), $items[0]->url);
        $this->assertSame('trophy', $items[0]->icon);
        // Never about oneself
        $this->assertSame([], $this->announcementsOf($this->ana));
        Mail::assertNothingOutgoing();
    }

    public function test_the_summary_waits_for_the_month_to_end_and_tells_once(): void
    {
        $this->earn($this->ana, $this->rare('Raro'));

        // A release within the same month leaves it waiting
        app()->call([new ReleaseAchievementAnnouncementsJob, 'handle']);
        $this->assertSame([], $this->announcementsOf($this->bea));

        $this->release();
        $this->assertCount(1, $this->announcementsOf($this->bea));

        $this->release();
        $this->assertSame(1, $this->announcementsOf($this->bea)[0]->count);
    }

    public function test_two_people_of_the_same_table_are_one_row_told_by_month_only(): void
    {
        $carla = $this->person('Carla');
        $this->allowAnnouncing($carla);
        $achievement = $this->rare('Tres sistemas');

        // One evaluation: both grants share their second
        $this->earn($carla, $achievement);
        $this->earn($this->ana, $achievement);

        $this->release();

        $items = $this->announcementsOf($this->bea);
        $this->assertCount(1, $items);
        $this->assertSame(1, $items[0]->count);
        $this->assertSame('Ana y Carla consiguieron logros raros en noviembre', $items[0]->title);
        $this->assertSame('Ana: «Tres sistemas»; Carla: «Tres sistemas»', $items[0]->body);
        $this->assertSame('/logros', $items[0]->url);
        // Neither the hour nor the day of the grants
        $this->assertDoesNotMatchRegularExpression('/\d{1,2}:\d{2}|\b15\b/', $items[0]->title.' '.$items[0]->body);
        $this->assertSame(1, DB::table('site_notifications')->where('user_id', $this->bea->id)->where('category', AchievementNoticeCategories::ANNOUNCEMENTS)->count());
    }

    public function test_many_owners_are_named_two_and_counted(): void
    {
        foreach (['Dani', 'Carla', 'Eva', 'Fran'] as $name) {
            $owner = $this->person($name);
            $this->allowAnnouncing($owner);
            $this->earn($owner, $this->rare('Raro de '.$name));
        }

        $this->earn($this->ana, $this->rare('Raro de Ana'));

        $this->release();

        $items = $this->announcementsOf($this->bea);
        $this->assertCount(1, $items);
        $this->assertSame('Ana, Carla y 3 más consiguieron logros raros en noviembre', $items[0]->title);
        $this->assertStringStartsWith('Ana: «Raro de Ana»; Carla: «Raro de Carla»; Dani', (string) $items[0]->body);
        // Each owner hears of the others, never of themselves
        $this->assertSame('Carla, Dani y 2 más consiguieron logros raros en noviembre', $this->announcementsOf($this->ana)[0]->title);
    }

    public function test_a_common_achievement_is_not_told(): void
    {
        $achievement = $this->manualAchievement('Primera partida');
        $this->rarity([(string) $achievement->id => 40.0]);

        $this->earn($this->ana, $achievement);
        $this->release();

        $this->assertSame([], $this->announcementsOf($this->bea));
    }

    public function test_the_threshold_comes_from_the_settings_and_is_exclusive(): void
    {
        $edge = $this->manualAchievement('Justo en el límite');
        $below = $this->manualAchievement('Por debajo');
        $this->rarity([(string) $edge->id => 20.0, (string) $below->id => 19.9]);
        $this->settings(new AchievementsSettings(announcementsRarityThreshold: 20.0));

        $this->earn($this->ana, $edge);
        $this->earn($this->ana, $below);
        $this->release();

        $this->assertSame('Ana: «Por debajo»', $this->announcementsOf($this->bea)[0]->body);
    }

    public function test_an_unknown_rarity_tells_nothing(): void
    {
        $achievement = $this->manualAchievement('Sin gente activa');
        $this->rarity([], activePeople: 0);

        $this->earn($this->ana, $achievement);
        $this->release();

        $this->assertSame([], $this->announcementsOf($this->bea));
    }

    public function test_a_challenge_achievement_is_told_however_common(): void
    {
        $achievement = $this->manualAchievement('Reto de otoño');
        $this->rarity([(string) $achievement->id => 80.0]);

        $this->earn($this->ana, $achievement, GrantSource::Challenge);
        $this->release();

        $this->assertCount(1, $this->announcementsOf($this->bea));
    }

    public function test_a_rare_manual_grant_is_told(): void
    {
        $this->earn($this->ana, $this->rare('Fundadora'), GrantSource::Manual);
        $this->release();

        $this->assertSame('Ana: «Fundadora»', $this->announcementsOf($this->bea)[0]->body);
    }

    public function test_a_bulk_grant_is_never_announced(): void
    {
        $this->earn($this->ana, $this->rare('Asistentes a las jornadas'), GrantSource::Bulk);
        $this->release();

        // It says who took part in one thing: the owner hears of it, nobody else
        $this->assertSame([], $this->announcementsOf($this->bea));
        $this->assertCount(1, $this->bellOf($this->ana, AchievementNoticeCategories::EARNED));
    }

    public function test_a_silent_grant_is_never_announced(): void
    {
        $this->earn($this->ana, $this->rare('Recalculado'), GrantSource::Rule, silent: true);
        $this->release();

        $this->assertSame([], $this->announcementsOf($this->bea));
    }

    public function test_nothing_is_told_without_the_owners_consent(): void
    {
        $this->allowAnnouncing($this->ana, announce: false);
        $carla = $this->person('Carla');

        $this->earn($this->ana, $this->rare('Raro pero discreto'));
        $this->earn($carla, $this->rare('Raro sin elegir'));
        $this->release();

        $this->assertSame([], $this->announcementsOf($this->bea));
    }

    public function test_an_owner_who_allows_it_before_the_summary_is_told(): void
    {
        $this->allowAnnouncing($this->ana, announce: false);
        $this->earn($this->ana, $this->rare('Raro'));
        $this->allowAnnouncing($this->ana);

        $this->release();

        $this->assertCount(1, $this->announcementsOf($this->bea));
    }

    public function test_hidden_achievements_are_never_announced(): void
    {
        $this->allowAnnouncing($this->ana, announce: true, hidden: true);

        $this->earn($this->ana, $this->rare('Oculto'));
        $this->release();

        $this->assertSame([], $this->announcementsOf($this->bea));
    }

    public function test_what_waited_while_announcements_were_off_is_never_told(): void
    {
        $this->earn($this->ana, $this->rare('Apagado'));
        $this->settings(new AchievementsSettings(announcements: false));
        $this->release();

        $this->settings(new AchievementsSettings(announcements: true));
        $this->release();

        $this->assertSame([], $this->announcementsOf($this->bea));
        $this->assertCount(1, $this->bellOf($this->ana, AchievementNoticeCategories::EARNED));
        $this->assertSame(0, DB::table('achievements_pending_notices')->where('kind', 'announcement')->count());
    }

    public function test_unreadable_settings_keep_everything_for_a_retry(): void
    {
        $this->earn($this->ana, $this->rare('Raro'));
        $this->unreadableSettings();

        $this->release();

        $this->assertSame([], $this->announcementsOf($this->bea));
        $this->assertSame(1, DB::table('achievements_pending_notices')->where('kind', 'announcement')->count());
    }

    public function test_unreadable_settings_try_again_and_fail_loudly_at_the_last_try(): void
    {
        $this->unreadableSettings();
        $job = new ReleaseAchievementAnnouncementsJob()->withFakeQueueInteractions();

        app()->call($job->handle(...));
        $job->assertReleased();

        $last = new ReleaseAchievementAnnouncementsJob()->withFakeQueueInteractions();
        $this->assertInstanceOf(FakeJob::class, $last->job);
        $last->job->attempts = $last->tries;

        $this->expectException(AchievementSettingsUnreadableException::class);
        app()->call($last->handle(...));
    }

    public function test_a_hidden_profile_is_announced_to_nobody(): void
    {
        $this->profileVisibility($this->ana, 'hidden');

        $this->earn($this->ana, $this->rare('Perfil oculto'));
        $this->release();

        $this->assertSame([], $this->announcementsOf($this->bea));
    }

    public function test_an_association_profile_is_announced_to_association_members_only(): void
    {
        $this->profileVisibility($this->ana, 'association');
        $socia = $this->person('Socia');
        $role = RoleModel::firstOrCreate(['name' => AssociationMembershipServiceInterface::DEFAULT_ROLE], ['display_name' => 'Socio']);
        $socia->roles()->attach($role->id);

        $this->earn($this->ana, $this->rare('Solo para socios'));
        $this->release();

        $this->assertCount(1, $this->announcementsOf($socia));
        $this->assertSame([], $this->announcementsOf($this->bea));
    }

    public function test_a_secret_is_only_counted_to_whoever_does_not_hold_it(): void
    {
        $secret = $this->rare('La puerta escondida', secret: true);
        $holder = $this->person('Dani');
        $this->grantAt($holder, (string) $secret->id);

        $this->earn($this->ana, $secret);
        $this->release();

        $this->assertSame('Ana: un logro secreto', $this->announcementsOf($this->bea)[0]->body);
        $this->assertSame('Ana: «La puerta escondida»', $this->announcementsOf($holder)[0]->body);
    }

    public function test_a_featured_secret_is_named_to_everybody(): void
    {
        $secret = $this->rare('La puerta escondida', secret: true);
        $this->earn($this->ana, $secret);
        $this->choose($this->ana, featured: [(string) $secret->id]);
        $this->allowAnnouncing($this->ana);

        $this->release();

        $this->assertSame('Ana: «La puerta escondida»', $this->announcementsOf($this->bea)[0]->body);
    }

    public function test_several_achievements_of_one_owner_are_named_in_order_of_name(): void
    {
        $this->earn($this->ana, $this->rare('Segundo raro'));
        $this->earn($this->ana, $this->rare('Tercero secreto', secret: true));
        $this->earn($this->ana, $this->rare('Primero raro'));

        $this->release();

        $this->assertSame('Ana: «Primero raro», «Segundo raro» y un logro secreto', $this->announcementsOf($this->bea)[0]->body);
    }

    public function test_the_reader_may_turn_announcements_off(): void
    {
        $this->turnOff($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS);

        $this->earn($this->ana, $this->rare('Raro'));
        $this->release();

        $this->assertSame([], $this->announcementsOf($this->bea));
    }

    public function test_the_summary_goes_out_in_batches_of_accounts_after_the_commit(): void
    {
        Bus::fake([DeliverAchievementAnnouncementsJob::class]);
        $anonymized = UserModel::factory()->create(['anonymized_at' => now()]);
        $achievement = $this->rare('Raro');
        $this->earn($this->ana, $achievement);

        $this->release();

        Bus::assertDispatched(DeliverAchievementAnnouncementsJob::class, fn (DeliverAchievementAnnouncementsJob $job): bool => $job->month === '2026-11'
            && $job->entries === [strtolower((string) $this->ana->id) => [strtolower((string) $achievement->id)]]
            && $job->afterCommit === true
            && in_array((string) $this->bea->id, $job->accountIds, true)
            && in_array((string) $this->ana->id, $job->accountIds, true)
            && ! in_array((string) $anonymized->id, $job->accountIds, true)
            && count($job->accountIds) <= DeliverAchievementAnnouncementsJob::BATCH_SIZE);
    }

    public function test_an_owner_who_stopped_consenting_before_delivery_is_not_told(): void
    {
        Bus::fake([DeliverAchievementAnnouncementsJob::class]);
        $this->earn($this->ana, $this->rare('Raro'));
        $this->release();
        $this->allowAnnouncing($this->ana, announce: false);

        $this->deliverDispatched();

        $this->assertSame([], $this->announcementsOf($this->bea));
    }

    public function test_an_owner_deactivated_before_delivery_is_not_told(): void
    {
        Bus::fake([DeliverAchievementAnnouncementsJob::class]);
        $carla = $this->person('Carla');
        $this->allowAnnouncing($carla);
        $this->earn($this->ana, $this->rare('Raro de Ana'));
        $this->earn($carla, $this->rare('Raro de Carla'));
        $this->release();
        $this->ana->delete();

        $this->deliverDispatched();

        $this->assertSame('Carla consiguió logros raros en noviembre', $this->announcementsOf($this->bea)[0]->title);
    }

    public function test_the_summary_runs_on_the_first_of_each_month_in_the_display_zone(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(
            static fn ($event): bool => $event->description === 'achievements:announcements',
        );

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('0 10 1 * *', $event->expression);
        $zone = $event->timezone;
        $this->assertSame(app(TimezoneServiceInterface::class)->displayTimezone()->getName(), $zone instanceof DateTimeZone ? $zone->getName() : $zone);
    }

    /**
     * An achievement held by 1 % of active people (the others set before keep their shares).
     */
    private function rare(string $name, bool $secret = false): AchievementModel
    {
        $achievement = $this->manualAchievement($name, $secret);
        $this->rareShares[(string) $achievement->id] = 1.0;
        $this->rarity($this->rareShares);

        return $achievement;
    }

    /**
     * @return list<SiteNotificationItemDTO>
     */
    private function announcementsOf(UserModel $user): array
    {
        return $this->bellOf($user, AchievementNoticeCategories::ANNOUNCEMENTS);
    }

    private function profileVisibility(UserModel $user, string $visibility): void
    {
        UserProfileModel::query()->where('user_id', $user->id)->update(['visibility' => $visibility]);
        $this->app->forgetScopedInstances();
    }

    private function unreadableSettings(): void
    {
        $unreadable = $this->createStub(AchievementsSettingsInterface::class);
        $unreadable->method('readStrictly')->willReturn(null);
        $this->app->scoped(AchievementsSettingsInterface::class, static fn (): AchievementsSettingsInterface => $unreadable);
    }

    /**
     * The first of the next month, when the summary of this one goes out.
     */
    private function release(): void
    {
        $this->travelTo(now()->startOfMonth()->addMonthNoOverflow()->setTime(10, 0));
        $this->app->forgetScopedInstances();
        app()->call([new ReleaseAchievementAnnouncementsJob, 'handle']);
    }

    private function deliverDispatched(): void
    {
        $this->app->forgetScopedInstances();
        Bus::dispatched(DeliverAchievementAnnouncementsJob::class)->each(static fn (DeliverAchievementAnnouncementsJob $job): mixed => app()->call($job->handle(...)));
    }
}
