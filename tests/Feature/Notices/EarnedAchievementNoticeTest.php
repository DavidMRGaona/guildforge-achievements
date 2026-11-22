<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Notices;

use App\Application\Mail\Services\MailCategoryRegistryInterface;
use App\Domain\Events\UserAnonymized;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Jobs\DeliverAchievementNoticesJob;
use Modules\Achievements\Notifications\AchievementNoticeCategories;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsNotices;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Modules\ModuleTestCase;

/**
 * «Has conseguido…» (achievements#6): the owner hears of what they just got, in the bell only,
 * one notice per batch of grants, never for what a recalculation or a silent bulk grant gives.
 */
final class EarnedAchievementNoticeTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsNotices;
    use BuildsShowcase;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('es');
        $this->withoutStarterCatalogue();
        $this->ana = $this->person('Ana');
    }

    public function test_the_categories_are_optional_site_only_and_on_by_default(): void
    {
        $registry = app(MailCategoryRegistryInterface::class);

        foreach ([AchievementNoticeCategories::EARNED, AchievementNoticeCategories::ANNOUNCEMENTS] as $key) {
            $category = $registry->find($key);

            $this->assertNotNull($category, $key);
            $this->assertSame($key, $category->key);
            $this->assertFalse($category->isMandatory());
            $this->assertTrue($category->site);
            $this->assertFalse($category->mail);
            $this->assertFalse($category->digestible);
            $this->assertSame('trophy', $category->icon);
        }
    }

    public function test_a_new_achievement_is_told_to_its_owner_in_the_bell(): void
    {
        Mail::fake();
        $achievement = $this->manualAchievement('Veterano de la mesa');

        $this->earn($this->ana, $achievement);

        $items = $this->bellOf($this->ana, AchievementNoticeCategories::EARNED);
        $this->assertCount(1, $items);
        $this->assertSame('Has conseguido «Veterano de la mesa»', $items[0]->title);
        $this->assertSame('Una descripción', $items[0]->body);
        $this->assertSame('/miembros/'.$this->handleOf($this->ana), $items[0]->url);
        $this->assertSame('trophy', $items[0]->icon);
        Mail::assertNothingOutgoing();
    }

    public function test_a_silent_grant_tells_nothing(): void
    {
        $this->earn($this->ana, $this->manualAchievement('Recalculado'), GrantSource::Rule, silent: true);
        $this->earn($this->ana, $this->manualAchievement('En lote callado'), GrantSource::Bulk, silent: true);

        $this->assertSame([], $this->bell($this->ana));
        $this->assertSame(0, DB::table('achievements_pending_notices')->count());
    }

    public function test_a_bulk_grant_with_notice_and_a_challenge_are_told(): void
    {
        $this->earn($this->ana, $this->manualAchievement('Asistentes'), GrantSource::Bulk);
        $this->earn($this->ana, $this->manualAchievement('Reto común'), GrantSource::Challenge);

        $this->assertCount(1, $this->bellOf($this->ana, AchievementNoticeCategories::EARNED));
        $this->assertSame(2, $this->bellOf($this->ana, AchievementNoticeCategories::EARNED)[0]->count);
    }

    public function test_a_secret_achievement_is_named_to_its_owner(): void
    {
        $this->earn($this->ana, $this->manualAchievement('El dado maldito', secret: true));

        $this->assertSame('Has conseguido «El dado maldito»', $this->bellOf($this->ana, AchievementNoticeCategories::EARNED)[0]->title);
    }

    public function test_many_grants_of_one_evaluation_become_one_notice(): void
    {
        Bus::fake([DeliverAchievementNoticesJob::class]);
        $first = $this->manualAchievement('Primera');
        $this->earn($this->ana, $first);
        $this->earn($this->ana, $this->manualAchievement('Segunda'));
        $this->earn($this->ana, $this->manualAchievement('Tercera'));

        // Each grant asks for the delivery, later and once per person while one is waiting
        Bus::assertDispatched(DeliverAchievementNoticesJob::class, static fn (DeliverAchievementNoticesJob $job): bool => $job->delay !== null);
        $this->assertSame(3, DB::table('achievements_pending_notices')->where('kind', 'earned')->count());
        $this->assertSame('achievements:notices:'.strtolower((string) $this->ana->id), new DeliverAchievementNoticesJob((string) $this->ana->id)->uniqueId());

        $this->deliver($this->ana);

        $items = $this->bellOf($this->ana, AchievementNoticeCategories::EARNED);
        $this->assertCount(1, $items);
        $this->assertSame(1, $items[0]->count);
        $this->assertSame('Has conseguido 3 logros', $items[0]->title);
        $this->assertSame('«Primera», «Segunda» y «Tercera»', $items[0]->body);
        $this->assertSame(0, DB::table('achievements_pending_notices')->where('kind', 'earned')->count());

        // Delivering again tells nothing twice
        $this->deliver($this->ana);
        $this->assertSame(1, $this->bellOf($this->ana, AchievementNoticeCategories::EARNED)[0]->count);
    }

    public function test_hundreds_of_grants_still_give_one_notice_with_a_short_body(): void
    {
        Bus::fake([DeliverAchievementNoticesJob::class]);

        for ($i = 1; $i <= 120; $i++) {
            $this->earn($this->ana, $this->manualAchievement('Logro número '.$i));
        }

        $this->deliver($this->ana);

        $items = $this->bellOf($this->ana, AchievementNoticeCategories::EARNED);
        $this->assertCount(1, $items);
        $this->assertSame('Has conseguido 120 logros', $items[0]->title);
        $this->assertNotNull($items[0]->body);
        $this->assertStringContainsString('y 117 más', $items[0]->body);
    }

    public function test_an_achievement_revoked_before_the_notice_is_not_told(): void
    {
        Bus::fake([DeliverAchievementNoticesJob::class]);
        $kept = $this->earn($this->ana, $this->manualAchievement('Se queda'));
        $revoked = $this->earn($this->ana, $this->manualAchievement('Se retira'));
        app(AchievementGranterInterface::class)->revoke($revoked->id, (string) UserModel::factory()->create()->id, 'Error');

        $this->deliver($this->ana);

        $items = $this->bellOf($this->ana, AchievementNoticeCategories::EARNED);
        $this->assertCount(1, $items);
        $this->assertSame('Has conseguido «Se queda»', $items[0]->title);
        $this->assertNotSame('', $kept->id);
    }

    public function test_the_owner_may_turn_the_notices_off(): void
    {
        $this->turnOff($this->ana, AchievementNoticeCategories::EARNED);

        $this->earn($this->ana, $this->manualAchievement('Sin aviso'));

        $this->assertSame([], $this->bellOf($this->ana, AchievementNoticeCategories::EARNED));
    }

    public function test_an_anonymized_account_is_told_nothing(): void
    {
        Bus::fake([DeliverAchievementNoticesJob::class]);
        $this->earn($this->ana, $this->manualAchievement('Antes de irse'));
        $this->ana->forceFill(['anonymized_at' => now()])->save();
        event(new UserAnonymized((string) $this->ana->id));

        $this->deliver($this->ana);

        $this->assertSame(0, DB::table('site_notifications')->where('user_id', $this->ana->id)->count());
        $this->assertSame(0, DB::table('achievements_pending_notices')->count());
    }

    private function deliver(UserModel $user): void
    {
        $this->app->forgetScopedInstances();
        app()->call([new DeliverAchievementNoticesJob((string) $user->id), 'handle']);
    }
}
