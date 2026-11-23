<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Challenges;

use App\Application\Authorization\Services\AssociationMembershipServiceInterface;
use App\Application\Exceptions\NothingToDrawException;
use App\Application\Profiles\Services\ProfileCardServiceInterface;
use App\Application\SiteBlocks\Services\SiteBlockRegistryInterface;
use App\Domain\SiteBlocks\SiteBlockPlacement;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Achievements\Filament\SiteBlocks\ChallengeSiteBlock;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\ChallengeModel;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Jobs\EvaluateChallengesJob;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsChallenges;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Modules\Achievements\Tests\Support\FakeProfileCards;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * What the site shows of the challenges: `/logros` and the site block. Only totals; the people who
 * completed one are those whose profile the viewer may see, by public name, and only if they show
 * their achievements.
 */
final class ChallengeBoardTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
    use BuildsChallenges;
    use BuildsShowcase;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->withoutStarterCatalogue();
        $this->registerInMemorySource();
        Bus::fake([EvaluateAchievementsJob::class, EvaluateChallengesJob::class]);
        $this->switchChallenges();
        $this->travelTo('2026-12-20 10:00:00');
    }

    public function test_the_page_shows_the_challenges_with_their_totals_and_the_viewers_own_part(): void
    {
        $ana = $this->person('Ana');
        $challenge = $this->challenge(['target' => 40], ['name' => '40 partidas en diciembre']);
        $this->counted($challenge, progress: 12, contributors: 5, contributions: [(string) $ana->id => 3]);

        $this->actingAs($ana)->get(route('achievements.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Achievements/Index', false)
                ->has('challenges', 1)
                ->where('challenges.0.name', '40 partidas en diciembre')
                ->where('challenges.0.state', 'running')
                ->where('challenges.0.counted', true)
                ->where('challenges.0.progress', 12)
                ->where('challenges.0.target', 40)
                ->where('challenges.0.unit', 'partidas')
                ->where('challenges.0.startsOn', '2026-12-01')
                ->where('challenges.0.endsOn', '2026-12-31')
                ->where('challenges.0.contributors', 5)
                ->where('challenges.0.contribution', 3)
                ->where('challenges.0.people', [])
                ->missing('challenges.0.rule'));

        $this->app['auth']->forgetGuards();
        $this->get(route('achievements.index'))
            ->assertInertia(fn (Assert $page): Assert => $page->where('challenges.0.contribution', null));
    }

    public function test_nothing_is_shown_with_the_switch_off(): void
    {
        $this->challenge();
        $this->switchChallenges(false);

        $this->get(route('achievements.index'))
            ->assertInertia(fn (Assert $page): Assert => $page->where('challenges', []));
    }

    public function test_upcoming_and_recent_ones_show_but_not_closed_or_long_gone_ones(): void
    {
        $this->challenge([], ['name' => 'Pronto', 'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31']);
        $this->challenge([], ['name' => 'Muy lejos', 'starts_on' => '2027-03-01', 'ends_on' => '2027-03-31']);
        $this->challenge([], ['name' => 'Reciente', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
        $this->challenge([], ['name' => 'Antiguo', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
        $closed = $this->challenge([], ['name' => 'Cerrado']);
        ChallengeModel::query()->whereKey($closed->id)->update(['closed_at' => '2026-12-05 10:00:00']);

        $this->get(route('achievements.index'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('challenges', 2)
                ->where('challenges.0.name', 'Reciente')
                ->where('challenges.0.state', 'ended')
                ->where('challenges.1.name', 'Pronto')
                ->where('challenges.1.state', 'upcoming'));
    }

    public function test_only_visible_people_who_show_their_achievements_are_named(): void
    {
        $challenge = $this->challenge(['target' => 1]);
        $ana = $this->person('Ana');
        $luis = $this->person('Luis', visibility: 'hidden');
        $marta = $this->person('Marta');
        $pablo = $this->person('Pablo');
        // Added to it, but the reward was taken away: not a completer
        $nora = $this->person('Nora');
        foreach ([$ana, $luis, $marta, $pablo] as $person) {
            $this->grantAt($person, $challenge->reward_achievement_id);
        }
        $this->choose($marta, hidden: true);
        $this->counted($challenge, progress: 5, contributors: 5, contributions: array_fill_keys(array_map(static fn (UserModel $user): string => (string) $user->id, [$ana, $luis, $marta, $pablo, $nora]), 1), completed: true);
        $viewer = $this->person('Quien mira');

        $response = $this->actingAs($viewer)->get(route('achievements.index'));

        $response->assertInertia(fn (Assert $page): Assert => $page
            ->where('challenges.0.state', 'completed')
            ->missing('challenges.0.completers')
            ->has('challenges.0.people', 2)
            ->where('challenges.0.people.0.name', 'Ana')
            ->where('challenges.0.people.1.name', 'Pablo')
            ->has('challenges.0.people.0.url')
            ->missing('challenges.0.people.0.id'));
        $response->assertDontSee((string) $ana->id);
        $response->assertDontSee('ana.login');
    }

    public function test_a_guest_only_sees_the_public_profiles_by_name(): void
    {
        $challenge = $this->challenge(['target' => 1]);
        $ana = $this->person('Ana', visibility: 'public');
        $luis = $this->person('Luis');
        $this->grantAt($ana, $challenge->reward_achievement_id);
        $this->grantAt($luis, $challenge->reward_achievement_id);
        $this->counted($challenge, progress: 2, contributors: 2, contributions: [(string) $ana->id => 1, (string) $luis->id => 1], completed: true);

        $this->get(route('achievements.index'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('challenges.0.people', 1)
                ->where('challenges.0.people.0.name', 'Ana'));
    }

    public function test_the_site_block_draws_the_chosen_challenge_or_the_one_ending_first(): void
    {
        $later = $this->challenge([], ['name' => 'Hasta enero', 'ends_on' => '2027-01-15']);
        $sooner = $this->challenge([], ['name' => 'Hasta fin de año']);
        $this->counted($sooner, progress: 3, contributors: 2, contributions: []);
        $block = $this->app->make(ChallengeSiteBlock::class);

        $this->assertTrue($block->isAvailable());
        $this->assertSame([SiteBlockPlacement::Footer, SiteBlockPlacement::Popup, SiteBlockPlacement::LinkPage], $block->placements());

        $props = $block->props([], null);
        $this->assertIsArray($props['challenge']);
        $this->assertSame('Hasta fin de año', $props['challenge']['name']);
        $this->assertSame(3, $props['challenge']['progress']);
        $this->assertSame([], $props['challenge']['people']);
        $this->assertSame(route('achievements.index'), $props['url']);

        $this->assertSame('Hasta enero', $block->props(['challenge' => $later->id], null)['challenge']['name'] ?? null);
    }

    public function test_the_site_block_draws_nothing_without_a_challenge_and_is_off_with_the_switch(): void
    {
        $block = $this->app->make(ChallengeSiteBlock::class);

        try {
            $block->props([], null);
            $this->fail('A block with no challenge must draw nothing.');
        } catch (NothingToDrawException) {
            $this->addToAssertionCount(1);
        }

        $this->switchChallenges(false);
        $this->assertFalse($this->app->make(ChallengeSiteBlock::class)->isAvailable());
    }

    public function test_the_core_registers_the_block(): void
    {
        $this->assertInstanceOf(ChallengeSiteBlock::class, $this->app->make(SiteBlockRegistryInterface::class)->find(ChallengeSiteBlock::KEY));
    }

    public function test_a_challenge_never_counted_says_so_and_hidden_ones_are_left_out(): void
    {
        $this->challenge([], ['name' => 'Sin contar']);
        // No source of venue bookings here, and a reward made secret
        $this->challenge(['kind' => 'venue_booking'], ['name' => 'Sin fuente']);
        $this->challenge([], ['name' => 'Recompensa secreta', 'reward_achievement_id' => $this->reward(['is_secret' => true])->id]);

        $this->get(route('achievements.index'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('challenges', 1)
                ->where('challenges.0.name', 'Sin contar')
                ->where('challenges.0.counted', false));
    }

    public function test_a_challenge_of_members_names_only_who_shows_the_membership_and_the_viewer(): void
    {
        $challenge = $this->challenge(['target' => 1], ['audience' => 'members']);
        $viewer = $this->person('Quien mira');
        $people = [$this->person('Ana'), $this->person('Luis'), $viewer];
        foreach ($people as $person) {
            $this->grantAt($person, $challenge->reward_achievement_id);
        }
        $this->counted($challenge, progress: 3, contributors: 3, contributions: array_fill_keys(array_map(static fn (UserModel $user): string => (string) $user->id, $people), 1), completed: true);
        // Ana shows «Socio» on her profile, Luis does not; the viewer sees their own card
        $this->app->instance(ProfileCardServiceInterface::class, new FakeProfileCards([
            strtolower((string) $people[0]->id) => ['Ana', true, false],
            strtolower((string) $people[1]->id) => ['Luis', false, false],
            strtolower((string) $viewer->id) => ['Quien mira', false, true],
        ]));

        $this->actingAs($viewer)->get(route('achievements.index'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('challenges.0.people', 2)
                ->where('challenges.0.people.0.name', 'Ana')
                ->where('challenges.0.people.1.name', 'Quien mira'));
    }

    public function test_whoever_is_not_a_member_is_not_told_their_part_in_a_challenge_of_members(): void
    {
        $this->challenge([], ['audience' => 'members']);
        $member = $this->createStub(AssociationMembershipServiceInterface::class);
        $member->method('isAssociationMember')->willReturn(false);
        $this->app->instance(AssociationMembershipServiceInterface::class, $member);

        $this->actingAs($this->person('Ana'))->get(route('achievements.index'))
            ->assertInertia(fn (Assert $page): Assert => $page->where('challenges.0.contribution', null));
    }

    /**
     * @param  array<string, int>  $contributions
     */
    private function counted(ChallengeModel $challenge, int $progress, int $contributors, array $contributions, bool $completed = false): void
    {
        ChallengeModel::query()->whereKey($challenge->id)->update([
            'progress' => $progress,
            'contributors' => $contributors,
            'completed_at' => $completed ? '2026-12-10 18:00:00' : null,
            'progress_at' => '2026-12-15 10:00:00',
        ]);

        foreach ($contributions as $userId => $contribution) {
            DB::table('achievements_challenge_contributors')->insert([
                'challenge_id' => $challenge->id,
                'user_id' => strtolower($userId),
                'contribution' => $contribution,
                'last_contribution_at' => '2026-12-10 18:00:00',
                'created_at' => '2026-12-10 18:00:00',
                'updated_at' => '2026-12-10 18:00:00',
            ]);
        }
    }
}
