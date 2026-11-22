<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Profiles;

use App\Application\Impersonation\Services\ImpersonationServiceInterface;
use App\Domain\Events\UserAnonymized;
use App\Domain\Impersonation\Enums\ImpersonationMode;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The owner stars up to three achievements and hides them from everybody else, from the section of
 * their own profile: a route of their own, refused (as an unknown address) to anybody else.
 */
final class ProfileAchievementsChoicesTest extends ModuleTestCase
{
    use BuildsAchievements;
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
        $this->ana = $this->person('Ana');
    }

    public function test_the_owner_saves_their_featured_achievements_and_goes_back(): void
    {
        $first = $this->heldBy($this->ana);
        $second = $this->heldBy($this->ana);

        $this->actingAs($this->ana)
            ->from('/miembros/'.$this->handleOf($this->ana))
            ->put($this->url($this->ana), ['hidden' => false, 'featured' => [$second, strtoupper($first)]])
            ->assertRedirect('/miembros/'.$this->handleOf($this->ana))
            ->assertStatus(303);

        $saved = app(AchievementPreferencesRepositoryInterface::class)->find((string) $this->ana->id);
        $this->assertSame([$second, $first], $saved->featured);
        $this->assertFalse($saved->hidden);
    }

    public function test_the_owner_hides_their_achievements(): void
    {
        $this->actingAs($this->ana)->put($this->url($this->ana), ['hidden' => true, 'featured' => []])->assertStatus(303);

        $this->assertTrue(app(AchievementPreferencesRepositoryInterface::class)->find((string) $this->ana->id)->hidden);
    }

    public function test_only_held_achievements_can_be_featured(): void
    {
        $notHeld = (string) $this->achievement($this->countRule())->id;
        $secret = (string) $this->achievement($this->countRule(), ['is_secret' => true])->id;

        foreach ([[$notHeld], [$secret], ['no-es-un-id']] as $featured) {
            $this->actingAs($this->ana)->put($this->url($this->ana), ['hidden' => false, 'featured' => $featured])
                ->assertSessionHasErrors(['featured' => 'Solo puedes destacar logros que tengas.']);
        }

        $this->assertDatabaseCount('achievements_preferences', 0);
    }

    public function test_at_most_three(): void
    {
        $ids = array_map(fn (): string => $this->heldBy($this->ana), range(1, 4));

        $this->actingAs($this->ana)->put($this->url($this->ana), ['hidden' => false, 'featured' => $ids])
            ->assertSessionHasErrors(['featured' => 'Puedes destacar 3 logros como mucho.']);
    }

    public function test_anybody_else_gets_the_404_of_an_unknown_address(): void
    {
        $admin = $this->userWith('achievements.manage', 'achievements.grant');
        $luis = $this->person('Luis');

        foreach ([$luis, $admin] as $other) {
            $this->actingAs($other)->put($this->url($this->ana), ['hidden' => true, 'featured' => []])->assertNotFound();
        }

        $this->actingAs($this->ana)->put('/logros/miembros/nadie-con-este-nombre', ['hidden' => true, 'featured' => []])->assertNotFound();
        $this->assertDatabaseCount('achievements_preferences', 0);
    }

    public function test_nothing_is_saved_while_impersonating_read_only(): void
    {
        $admin = UserModel::factory()->admin()->create();
        $this->actingAs($admin);
        $this->app->make(ImpersonationServiceInterface::class)->start($admin, $this->ana, ImpersonationMode::ReadOnly);

        $this->putJson($this->url($this->ana), ['hidden' => true, 'featured' => []])
            ->assertForbidden()
            ->assertJson(['error' => 'impersonation_read_only']);

        $this->assertDatabaseCount('achievements_preferences', 0);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->put($this->url($this->ana), ['hidden' => true, 'featured' => []])->assertRedirect();

        $this->assertDatabaseCount('achievements_preferences', 0);
    }

    public function test_an_anonymized_account_forgets_its_choices(): void
    {
        $this->choose($this->ana, hidden: true);

        event(new UserAnonymized((string) $this->ana->id));

        $this->assertTrue(DB::table('achievements_preferences')->doesntExist());
    }

    private function heldBy(UserModel $user): string
    {
        $id = strtolower((string) $this->achievement($this->countRule())->id);
        $this->grantAt($user, $id);

        return $id;
    }

    private function url(UserModel $user): string
    {
        return '/logros/miembros/'.$this->handleOf($user);
    }
}
