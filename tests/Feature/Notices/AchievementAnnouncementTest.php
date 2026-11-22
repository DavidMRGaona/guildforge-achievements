<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Notices;

use App\Application\Authorization\Services\AssociationMembershipServiceInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserProfileModel;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Jobs\DeliverAchievementAnnouncementsJob;
use Modules\Achievements\Jobs\ReleaseAchievementAnnouncementsJob;
use Modules\Achievements\Notifications\AchievementNoticeCategories;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsNotices;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Modules\ModuleTestCase;

/**
 * «Ana ha conseguido…» (achievements#6): a rare achievement, or one of a challenge, is told to whoever
 * may see its owner's profile, only when the owner asked for it and the mechanic is on. Secrets the
 * recipient does not hold are only counted.
 */
final class AchievementAnnouncementTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsNotices;
    use BuildsShowcase;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    private UserModel $bea;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('es');
        $this->withoutStarterCatalogue();
        $this->ana = $this->person('Ana', 'members');
        $this->bea = $this->person('Bea');
        $this->allowAnnouncing($this->ana);
    }

    public function test_a_rare_achievement_is_told_to_whoever_may_see_the_profile(): void
    {
        Mail::fake();
        $achievement = $this->manualAchievement('Maestro de campañas');
        $this->rarity([(string) $achievement->id => 4.5]);

        $this->earn($this->ana, $achievement);
        $this->release();

        $items = $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS);
        $this->assertCount(1, $items);
        $this->assertSame('Ana ha conseguido «Maestro de campañas»', $items[0]->title);
        $this->assertSame('/miembros/'.$this->handleOf($this->ana), $items[0]->url);
        $this->assertSame('trophy', $items[0]->icon);
        $this->assertNull($items[0]->body);
        // The owner hears of it as their own, never as an announcement
        $this->assertSame([], $this->bellOf($this->ana, AchievementNoticeCategories::ANNOUNCEMENTS));
        $this->assertCount(1, $this->bellOf($this->ana, AchievementNoticeCategories::EARNED));
        Mail::assertNothingOutgoing();
    }

    public function test_a_common_achievement_is_not_told(): void
    {
        $achievement = $this->manualAchievement('Primera partida');
        $this->rarity([(string) $achievement->id => 40.0]);

        $this->earn($this->ana, $achievement);

        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
    }

    public function test_the_threshold_comes_from_the_settings_and_is_exclusive(): void
    {
        $achievement = $this->manualAchievement('Justo en el límite');
        $this->rarity([(string) $achievement->id => 20.0]);
        $this->settings(new AchievementsSettings(announcementsRarityThreshold: 20.0));

        $this->earn($this->ana, $achievement);
        $this->release();
        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));

        $rarer = $this->manualAchievement('Por debajo');
        $this->rarity([(string) $rarer->id => 19.9]);
        $this->earn($this->ana, $rarer);
        $this->release();
        $this->assertCount(1, $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
    }

    public function test_an_unknown_rarity_tells_nothing(): void
    {
        $achievement = $this->manualAchievement('Sin gente activa');
        $this->rarity([], activePeople: 0);

        $this->earn($this->ana, $achievement);
        $this->release();

        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
    }

    public function test_a_challenge_achievement_is_told_however_common(): void
    {
        $achievement = $this->manualAchievement('Reto de otoño');
        $this->rarity([(string) $achievement->id => 80.0]);

        $this->earn($this->ana, $achievement, GrantSource::Challenge);
        $this->release();

        $this->assertCount(1, $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
    }

    public function test_a_bulk_grant_is_never_announced(): void
    {
        $achievement = $this->manualAchievement('Asistentes a las jornadas');
        $this->rarity([(string) $achievement->id => 1.0]);

        $this->earn($this->ana, $achievement, GrantSource::Bulk);
        $this->release();

        // It says who took part in one thing: the owner hears of it, nobody else
        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
        $this->assertCount(1, $this->bellOf($this->ana, AchievementNoticeCategories::EARNED));
    }

    public function test_a_silent_grant_is_never_announced(): void
    {
        $achievement = $this->manualAchievement('Recalculado');
        $this->rarity([(string) $achievement->id => 1.0]);

        $this->earn($this->ana, $achievement, GrantSource::Rule, silent: true);
        $this->release();

        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
    }

    public function test_nothing_is_told_without_the_owners_consent(): void
    {
        $this->allowAnnouncing($this->ana, announce: false);
        $achievement = $this->manualAchievement('Raro pero discreto');
        $this->rarity([(string) $achievement->id => 1.0]);

        $this->earn($this->ana, $achievement);
        $this->release();

        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
    }

    public function test_nobody_who_never_chose_is_announced(): void
    {
        $carla = $this->person('Carla');
        $achievement = $this->manualAchievement('Raro por defecto');
        $this->rarity([(string) $achievement->id => 1.0]);

        $this->earn($carla, $achievement);
        $this->release();

        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
    }

    public function test_hidden_achievements_are_never_announced(): void
    {
        $this->allowAnnouncing($this->ana, announce: true, hidden: true);
        $achievement = $this->manualAchievement('Oculto');
        $this->rarity([(string) $achievement->id => 1.0]);

        $this->earn($this->ana, $achievement);
        $this->release();

        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
    }

    public function test_nothing_is_told_with_announcements_switched_off(): void
    {
        $this->settings(new AchievementsSettings(announcements: false));
        $achievement = $this->manualAchievement('Apagado');
        $this->rarity([(string) $achievement->id => 1.0]);

        $this->earn($this->ana, $achievement);
        $this->release();

        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
        $this->assertCount(1, $this->bellOf($this->ana, AchievementNoticeCategories::EARNED));
    }

    public function test_a_hidden_profile_is_announced_to_nobody(): void
    {
        $this->profileVisibility($this->ana, 'hidden');
        $achievement = $this->manualAchievement('Perfil oculto');
        $this->rarity([(string) $achievement->id => 1.0]);

        $this->earn($this->ana, $achievement);
        $this->release();

        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
    }

    public function test_an_association_profile_is_announced_to_association_members_only(): void
    {
        $this->profileVisibility($this->ana, 'association');
        $socia = $this->person('Socia');
        $role = RoleModel::firstOrCreate(['name' => AssociationMembershipServiceInterface::DEFAULT_ROLE], ['display_name' => 'Socio']);
        $socia->roles()->attach($role->id);
        $achievement = $this->manualAchievement('Solo para socios');
        $this->rarity([(string) $achievement->id => 1.0]);

        $this->earn($this->ana, $achievement);
        $this->release();

        $this->assertCount(1, $this->bellOf($socia, AchievementNoticeCategories::ANNOUNCEMENTS));
        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
    }

    public function test_a_secret_is_only_counted_to_whoever_does_not_hold_it(): void
    {
        $secret = $this->manualAchievement('La puerta escondida', secret: true);
        $this->rarity([(string) $secret->id => 1.0]);
        $holder = $this->person('Dani');
        $this->grantAt($holder, (string) $secret->id);

        $this->earn($this->ana, $secret);
        $this->release();

        $this->assertSame('Ana ha conseguido un logro secreto', $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS)[0]->title);
        $this->assertSame('Ana ha conseguido «La puerta escondida»', $this->bellOf($holder, AchievementNoticeCategories::ANNOUNCEMENTS)[0]->title);
    }

    public function test_a_featured_secret_is_named_to_everybody(): void
    {
        $secret = $this->manualAchievement('La puerta escondida', secret: true);
        $this->rarity([(string) $secret->id => 1.0]);
        $this->earn($this->ana, $secret);
        $this->choose($this->ana, featured: [(string) $secret->id]);
        $this->allowAnnouncing($this->ana);

        $this->release();

        $this->assertSame('Ana ha conseguido «La puerta escondida»', $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS)[0]->title);
    }

    public function test_several_rare_achievements_at_once_are_one_announcement(): void
    {
        $first = $this->manualAchievement('Primero raro');
        $second = $this->manualAchievement('Segundo raro');
        $secret = $this->manualAchievement('Tercero secreto', secret: true);
        $this->rarity([(string) $first->id => 1.0, (string) $second->id => 2.0, (string) $secret->id => 3.0]);
        $this->earn($this->ana, $first);
        $this->earn($this->ana, $second);
        $this->earn($this->ana, $secret);

        $this->release();

        $items = $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS);
        $this->assertCount(1, $items);
        $this->assertSame('Ana ha conseguido 3 logros', $items[0]->title);
        $this->assertSame('«Primero raro», «Segundo raro» y un logro secreto', $items[0]->body);
    }

    public function test_the_recipient_may_turn_announcements_off(): void
    {
        $this->turnOff($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS);
        $achievement = $this->manualAchievement('Raro');
        $this->rarity([(string) $achievement->id => 1.0]);

        $this->earn($this->ana, $achievement);
        $this->release();

        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
    }

    public function test_announcements_go_out_in_batches_of_accounts_without_the_owner(): void
    {
        Bus::fake([DeliverAchievementAnnouncementsJob::class]);
        $anonymized = UserModel::factory()->create(['anonymized_at' => now()]);
        $achievement = $this->manualAchievement('Raro');
        $this->rarity([(string) $achievement->id => 1.0]);
        $this->earn($this->ana, $achievement);

        $this->release();

        Bus::assertDispatched(DeliverAchievementAnnouncementsJob::class, fn (DeliverAchievementAnnouncementsJob $job): bool => $job->ownerId === strtolower((string) $this->ana->id)
            && $job->achievementIds === [strtolower((string) $achievement->id)]
            && in_array((string) $this->bea->id, $job->accountIds, true)
            && ! in_array((string) $this->ana->id, $job->accountIds, true)
            && ! in_array((string) $anonymized->id, $job->accountIds, true)
            && count($job->accountIds) <= DeliverAchievementAnnouncementsJob::BATCH_SIZE);
    }

    public function test_an_owner_who_stopped_consenting_before_delivery_is_not_announced(): void
    {
        Bus::fake([DeliverAchievementAnnouncementsJob::class]);
        $achievement = $this->manualAchievement('Raro');
        $this->rarity([(string) $achievement->id => 1.0]);
        $this->earn($this->ana, $achievement);
        $this->release();
        $this->allowAnnouncing($this->ana, announce: false);

        $this->app->forgetScopedInstances();
        Bus::dispatched(DeliverAchievementAnnouncementsJob::class)->each(static fn (DeliverAchievementAnnouncementsJob $job): mixed => app()->call($job->handle(...)));

        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
    }

    private function profileVisibility(UserModel $user, string $visibility): void
    {
        UserProfileModel::query()->where('user_id', $user->id)->update(['visibility' => $visibility]);
        $this->app->forgetScopedInstances();
    }

    public function test_nothing_is_told_to_others_until_the_daily_release(): void
    {
        $achievement = $this->manualAchievement('Raro');
        $this->rarity([(string) $achievement->id => 1.0]);

        $this->earn($this->ana, $achievement);

        // The owner hears of it at once; others only in the release, never at the moment it was got
        $this->assertCount(1, $this->bellOf($this->ana, AchievementNoticeCategories::EARNED));
        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));

        $this->release();
        $this->assertCount(1, $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));

        // Taken once: a second release tells nothing again
        $this->release();
        $this->assertSame(1, $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS)[0]->count);
    }

    public function test_the_release_runs_once_a_day_in_the_display_zone(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(
            static fn ($event): bool => $event->description === 'achievements:announcements',
        );

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('0 10 * * *', $event->expression);
        $zone = $event->timezone;
        $this->assertSame(app(TimezoneServiceInterface::class)->displayTimezone()->getName(), $zone instanceof DateTimeZone ? $zone->getName() : $zone);
    }

    public function test_each_owner_goes_out_at_a_moment_of_its_own_within_the_spread(): void
    {
        Bus::fake([DeliverAchievementAnnouncementsJob::class]);
        $this->travelTo(Date::parse('2026-11-23 10:00:00'));
        $carla = $this->person('Carla');
        $this->allowAnnouncing($carla);
        $achievement = $this->manualAchievement('Raro de una misma mesa');
        $this->rarity([(string) $achievement->id => 1.0]);
        $this->earn($this->ana, $achievement);
        $this->earn($carla, $achievement);

        $this->release();

        $delays = [];
        Bus::dispatched(DeliverAchievementAnnouncementsJob::class)->each(function (DeliverAchievementAnnouncementsJob $job) use (&$delays): void {
            $this->assertInstanceOf(DateTimeInterface::class, $job->delay);
            $seconds = $job->delay->getTimestamp() - now()->getTimestamp();
            $this->assertGreaterThanOrEqual(0, $seconds);
            $this->assertLessThanOrEqual(ReleaseAchievementAnnouncementsJob::SPREAD_SECONDS, $seconds);
            $delays[$job->ownerId][] = $seconds;
        });

        $this->assertCount(2, $delays);
        // All the batches of one owner go out together
        foreach ($delays as $ownerDelays) {
            $this->assertCount(1, array_unique($ownerDelays));
        }
    }

    public function test_unreadable_settings_keep_the_announcements_for_the_next_release(): void
    {
        $achievement = $this->manualAchievement('Raro');
        $this->rarity([(string) $achievement->id => 1.0]);
        $this->earn($this->ana, $achievement);
        $unreadable = $this->createStub(AchievementsSettingsInterface::class);
        $unreadable->method('readStrictly')->willReturn(null);
        $this->app->scoped(AchievementsSettingsInterface::class, static fn (): AchievementsSettingsInterface => $unreadable);

        $this->release();

        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
        $this->assertSame(1, DB::table('achievements_pending_notices')->where('kind', 'announcement')->count());
    }

    public function test_what_waited_while_announcements_were_off_is_never_told(): void
    {
        $achievement = $this->manualAchievement('Raro');
        $this->rarity([(string) $achievement->id => 1.0]);
        $this->earn($this->ana, $achievement);
        $this->settings(new AchievementsSettings(announcements: false));
        $this->release();

        $this->settings(new AchievementsSettings(announcements: true));
        $this->release();

        $this->assertSame([], $this->bellOf($this->bea, AchievementNoticeCategories::ANNOUNCEMENTS));
    }

    private function release(): void
    {
        $this->app->forgetScopedInstances();
        app()->call([new ReleaseAchievementAnnouncementsJob, 'handle']);
    }
}
