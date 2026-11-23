<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Profiles;

use App\Application\Profiles\DTOs\ProfileBadgeDTO;
use App\Application\Profiles\ProfileBadgeIcons;
use App\Application\Profiles\Services\ProfileBadgesInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Repositories\AchievementPreferencesRepositoryInterface;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Infrastructure\Profiles\FeaturedAchievementBadges;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Up to three featured achievements under the name, on the card and next to the author in the
 * forum: the ones the owner starred, else their rarest. Never a secret one they did not choose,
 * never anything of someone who hid their achievements, and only for profiles the viewer may see
 * (the core decides that before asking).
 */
final class FeaturedAchievementBadgesTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsShowcase;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    private UserModel $luis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->withoutStarterCatalogue();
        $this->travelTo('2026-11-22 10:00:00');
        $this->ana = $this->person('Ana');
        $this->luis = $this->person('Luis');
    }

    public function test_without_a_choice_the_three_rarest_are_featured(): void
    {
        $common = $this->held('Común', holders: 4);
        $rare = $this->held('Rara', holders: 1);
        $uncommon = $this->held('Poco común', holders: 2);
        $fairly = $this->held('Bastante común', holders: 3);
        $this->refreshRarity();

        $this->assertSame(['Rara', 'Poco común', 'Bastante común'], $this->labels($this->ana));
        $this->assertNotContains($common, $this->keys($this->ana));
        $this->assertSame('achievements.'.$rare, $this->badges($this->ana)[0]->key);
        $this->assertSame([$rare, $uncommon, $fairly], array_map(static fn (string $key): string => substr($key, 13), $this->keys($this->ana)));
    }

    public function test_a_share_of_zero_counts_as_unknown_and_goes_last(): void
    {
        // Ana is not active; Luis and Marta are
        $marta = $this->person('Marta');
        $this->factAt($this->luis, '2026-10-01 18:00:00');
        $this->factAt($marta, '2026-10-01 18:00:00');
        $half = (string) $this->achievement($this->countRule(), ['name' => 'La mitad'])->id;
        $everybody = (string) $this->achievement($this->countRule(), ['name' => 'Todo el mundo'])->id;
        $onlyAna = (string) $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Solo Ana'])->id;

        foreach ([$half, $everybody, $onlyAna] as $id) {
            $this->grantAt($this->ana, $id, '2026-11-20 18:00:00');
        }

        $this->grantAt($this->luis, $half);
        $this->grantAt($this->luis, $everybody);
        $this->grantAt($marta, $everybody);
        $this->refreshRarity();

        $this->assertSame(['La mitad', 'Todo el mundo', 'Solo Ana'], $this->labels($this->ana));
    }

    public function test_a_grant_of_today_is_featured_only_after_the_next_nightly_photo(): void
    {
        $old = $this->held('Antigua');
        $this->refreshRarity();
        $this->travel(2)->hours();
        $new = (string) $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Nueva'])->id;
        $this->grantAt($this->ana, $new, now()->format('Y-m-d H:i:s'));
        $this->choose($this->ana, featured: [$new, $old]);

        $this->assertSame(['Antigua'], $this->labels($this->ana, afterTheNight: false));

        // The next night
        $this->travelTo('2026-11-23 03:30:00');
        $this->assertSame(['Nueva', 'Antigua'], $this->labels($this->ana));
    }

    public function test_the_owners_choice_wins_in_their_order(): void
    {
        $first = $this->held('Primera');
        $second = $this->held('Segunda');
        $this->held('Tercera');
        $this->choose($this->ana, featured: [$second, $first]);

        $this->assertSame(['Segunda', 'Primera'], $this->labels($this->ana));
    }

    public function test_a_chosen_achievement_no_longer_held_is_skipped(): void
    {
        $kept = $this->held('Conservada');
        $gone = $this->held('Retirada');
        $this->choose($this->ana, featured: [$gone, $kept]);
        DB::table('achievements_grants')->where('achievement_id', $gone)->delete();

        $this->assertSame(['Conservada'], $this->labels($this->ana));
    }

    public function test_a_secret_is_featured_only_when_the_owner_chose_it(): void
    {
        $secret = $this->held('El secreto', secret: true);
        $this->held('Visible');

        $this->assertSame(['Visible'], $this->labels($this->ana));

        $this->choose($this->ana, featured: [$secret]);

        $this->assertSame(['El secreto'], $this->labels($this->ana));
    }

    public function test_an_achievement_made_secret_leaves_everybodys_featured_badges(): void
    {
        $turned = $this->held('Se vuelve secreto');
        $kept = $this->held('Sigue visible');
        $this->grantAt($this->luis, $turned);
        $this->choose($this->ana, featured: [$turned, $kept]);
        $this->choose($this->luis, featured: [$turned]);

        AchievementModel::query()->findOrFail($turned)->update(['is_secret' => true]);

        $this->assertSame(['Sigue visible'], $this->labels($this->ana));
        $this->assertSame([$kept], app(AchievementPreferencesRepositoryInterface::class)->find((string) $this->ana->id)->featured);
        $this->assertSame([], app(AchievementPreferencesRepositoryInterface::class)->find((string) $this->luis->id)->featured);
    }

    public function test_a_secret_chosen_while_secret_stays_featured_through_other_edits(): void
    {
        $secret = $this->held('El secreto', secret: true);
        $this->choose($this->ana, featured: [$secret]);

        AchievementModel::query()->findOrFail($secret)->update(['name' => 'El gran secreto']);

        $this->assertSame(['El gran secreto'], $this->labels($this->ana));
    }

    public function test_a_secret_not_held_is_never_featured(): void
    {
        $secret = (string) $this->achievement($this->countRule(), ['name' => 'El secreto', 'is_secret' => true])->id;
        $this->choose($this->ana, featured: [$secret]);

        $this->assertSame([], $this->badges($this->ana));
    }

    public function test_someone_who_hid_their_achievements_shows_no_badges_to_anybody(): void
    {
        $this->held('Primera');
        $this->choose($this->ana, hidden: true);

        $this->assertSame([], $this->badges($this->ana, $this->luis));
        $this->assertSame([], $this->badges($this->ana, $this->ana));
    }

    public function test_a_hidden_profile_shows_no_badges_and_its_source_is_never_asked(): void
    {
        $hidden = $this->person('Oculta', 'hidden');
        $this->grantAt($hidden, (string) $this->achievement($this->countRule())->id);
        $this->app->forgetScopedInstances();

        $this->assertSame([], app(ProfileBadgesInterface::class)->forAuthors([(string) $hidden->id], $this->luis));
    }

    public function test_the_badges_follow_the_core_rules(): void
    {
        $this->held('Primera', icon: 'dices', colour: 'teal');

        $badge = $this->badges($this->ana)[0];

        $this->assertTrue(ProfileBadgeIcons::isKnown($badge->icon));
        $this->assertSame(['dices', 'teal', '/logros', null], [$badge->icon, $badge->colour->value, $badge->url, $badge->imageUrl]);
    }

    public function test_rarity_switched_off_features_the_newest(): void
    {
        $this->held('Antigua', holders: 1, when: '2026-01-01 18:00:00');
        $this->held('Nueva', holders: 3, when: '2026-11-01 18:00:00');
        $this->held('Media', holders: 2, when: '2026-06-01 18:00:00');
        $this->held('Vieja', holders: 1, when: '2025-01-01 18:00:00');
        $this->refreshRarity();
        app(AchievementsSettingsInterface::class)->save(AchievementsSettings::fromArray(['rarity' => false]));

        $this->assertSame(['Nueva', 'Media', 'Antigua'], $this->labels($this->ana));
    }

    public function test_among_equals_the_newest_month_then_the_name_never_the_instant(): void
    {
        // Rarity off: every share is unknown, so only month and name decide
        $this->held('Zeta', when: '2026-11-28 18:00:00');
        $this->held('Beta', when: '2026-11-02 18:00:00');
        $this->held('Alfa', when: '2026-11-15 18:00:00');
        $this->held('Octubre', when: '2026-10-31 10:00:00');
        app(AchievementsSettingsInterface::class)->save(AchievementsSettings::fromArray(['rarity' => false]));
        // The night after the last of them
        $this->travelTo('2026-11-29 03:30:00');

        $this->assertSame(['Alfa', 'Beta', 'Zeta'], $this->labels($this->ana));
    }

    public function test_every_account_of_a_page_is_answered_with_a_constant_number_of_queries(): void
    {
        $achievement = $this->held('Primera');
        $people = [$this->ana];

        foreach (range(1, 6) as $n) {
            $person = $this->person('Persona'.$n);
            $this->grantAt($person, $achievement);
            $people[] = $person;
        }

        $this->refreshRarity();
        $ids = array_map(static fn (UserModel $user): string => strtolower((string) $user->id), $people);
        $source = fn (): FeaturedAchievementBadges => $this->freshSource();

        $source()->badgesFor(array_slice($ids, 0, 2));
        $few = $this->countQueries(fn () => $source()->badgesFor(array_slice($ids, 0, 2)));
        $many = $this->countQueries(fn () => $source()->badgesFor($ids));

        $this->assertSame($few, $many);
        $this->assertCount(7, $source()->badgesFor($ids));
    }

    /**
     * An achievement Ana holds, with as many holders as asked (Ana among them), all of them active.
     */
    private function held(string $name, int $holders = 1, bool $secret = false, string $icon = 'trophy', string $colour = 'amber', string $when = '2026-10-01 18:00:00'): string
    {
        $id = (string) $this->achievement($this->countRule(), ['name' => $name, 'is_secret' => $secret, 'icon' => $icon, 'colour' => $colour])->id;
        $this->grantAt($this->ana, $id, $when);
        $this->factAt($this->ana, '2026-10-01 18:00:00');

        for ($n = 2; $n <= $holders; $n++) {
            $other = $this->person($name.' '.$n);
            $this->factAt($other, '2026-10-01 18:00:00');
            $this->grantAt($other, $id);
        }

        return strtolower($id);
    }

    /**
     * @return list<ProfileBadgeDTO>
     */
    private function badges(UserModel $owner, ?UserModel $viewer = null, bool $afterTheNight = true): array
    {
        $this->app->forgetScopedInstances();

        // Badges are what the nightly photo holds: by default, the night passes first
        if ($afterTheNight) {
            $this->refreshRarity();
            $this->app->forgetScopedInstances();
        }

        return app(ProfileBadgesInterface::class)->forAuthors([(string) $owner->id], $viewer ?? $this->luis)[strtolower((string) $owner->id)] ?? [];
    }

    /**
     * @return list<string>
     */
    private function labels(UserModel $owner, bool $afterTheNight = true): array
    {
        return array_map(static fn (ProfileBadgeDTO $badge): string => $badge->label, $this->badges($owner, afterTheNight: $afterTheNight));
    }

    /**
     * @return list<string>
     */
    private function keys(UserModel $owner): array
    {
        return array_map(static fn (ProfileBadgeDTO $badge): string => $badge->key, $this->badges($owner));
    }

    private function freshSource(): FeaturedAchievementBadges
    {
        $this->app->forgetScopedInstances();

        return app(FeaturedAchievementBadges::class);
    }

    private function countQueries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
