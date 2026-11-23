<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Profiles;

use App\Application\Profiles\Services\ProfileSectionRegistryInterface;
use App\Application\Profiles\Services\ProfileStatRegistryInterface;
use App\Application\Services\EmailVerificationPolicyInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Application\Services\AchievementShowcaseInterface;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Infrastructure\Profiles\AchievementsCountProfileStat;
use Modules\Achievements\Infrastructure\Profiles\AchievementsProfileSection;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Activity\InMemoryActivitySource;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * «Logros» on a public profile: what the owner got, with its date and rarity, for anybody the
 * core let see the profile; what is left and its progress for the owner alone; secrets masked for
 * whoever does not hold them; nothing for anybody else once the owner hid their achievements.
 */
final class AchievementsProfileSectionTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;
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

    public function test_the_module_offers_the_section_and_the_count(): void
    {
        $section = app(ProfileSectionRegistryInterface::class)->find(AchievementsProfileSection::KEY);

        $this->assertSame(AchievementsProfileSection::class, $section?->class);
        $this->assertSame('achievements', $section->module);
        $this->assertSame(AchievementsCountProfileStat::class, app(ProfileStatRegistryInterface::class)->find(AchievementsCountProfileStat::KEY)?->class);
    }

    public function test_anybody_who_sees_the_profile_sees_the_earned_ones_with_month_and_rarity(): void
    {
        $first = $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        $ten = $this->achievement($this->countRule(threshold: 10), ['name' => 'Diez partidas']);
        $this->grantAt($this->ana, (string) $first->id, '2026-09-01 18:00:00');
        $this->grantAt($this->ana, (string) $ten->id, '2026-10-01 18:00:00');
        $this->grantAt($this->luis, (string) $first->id, '2026-09-02 18:00:00');
        $this->factAt($this->ana, '2026-10-01 18:00:00');
        $this->factAt($this->luis, '2026-10-01 18:00:00');
        $this->refreshRarity();

        foreach ([null, $this->luis] as $viewer) {
            $props = $this->achievementsSection($this->render($this->ana, $viewer))?->props;

            $this->assertNotNull($props);
            $this->assertSame(['Diez partidas', 'Primera partida'], array_column($props['earned'], 'name'));
            $this->assertNull($props['earned'][0]['grantedAt']);
            $this->assertSame(['2026-10', '2026-09'], array_column($props['earned'], 'grantedMonth'));
            $this->assertSame([50, 100], array_column($props['earned'], 'rarity'));
            $this->assertSame([], $props['locked']);
            $this->assertNull($props['owner']);
        }
    }

    public function test_anybody_but_the_owner_gets_the_month_alone(): void
    {
        $first = $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        $ten = $this->achievement($this->countRule(threshold: 10), ['name' => 'Diez partidas']);
        $this->grantAt($this->ana, (string) $first->id, '2026-11-14 19:31:07');
        $this->grantAt($this->ana, (string) $ten->id, '2026-11-21 10:12:45');

        foreach ([null, $this->luis] as $viewer) {
            // The night passes: others see what the nightly photo holds
            $this->refreshRarity();
            $props = $this->achievementsSection($this->render($this->ana, $viewer))?->props;
            $json = (string) json_encode($props);

            // Neither the day nor the time of any grant travels, in any form
            foreach (['14', '21', '19:31', '20:31', '10:12', '11:12', ':07', ':45', 'T'] as $piece) {
                $this->assertStringNotContainsString('2026-11-'.$piece, $json);
            }

            $this->assertStringNotContainsString('31:07', $json);
            $this->assertStringNotContainsString('12:45', $json);
            $earned = $props['earned'] ?? [];
            $this->assertSame([null, null], array_column($earned, 'grantedAt'));
            $this->assertSame(['2026-11', '2026-11'], array_column($earned, 'grantedMonth'));
            // Within a month by name, so the order never tells which came first
            $this->assertSame(['Diez partidas', 'Primera partida'], array_column($earned, 'name'));
        }

        $own = $this->achievementsSection($this->render($this->ana, $this->ana))?->props['earned'] ?? [];
        $this->assertSame(['Diez partidas', 'Primera partida'], array_column($own, 'name'));
        $this->assertSame(['2026-11-21T11:12:45+01:00', '2026-11-14T20:31:07+01:00'], array_map($this->isoInMadrid(...), array_column($own, 'grantedAt')));
    }

    public function test_the_month_is_the_display_zones_at_the_turn_of_a_month(): void
    {
        // Storage in UTC (setUp); the display zone is Madrid: +1 in winter, +2 in summer
        $winter = $this->achievement($this->countRule(), ['name' => 'Invierno']);
        $summerBefore = $this->achievement($this->countRule(), ['name' => 'Verano antes']);
        $summerAfter = $this->achievement($this->countRule(), ['name' => 'Verano después']);
        $this->grantAt($this->ana, (string) $winter->id, '2026-11-30 23:30:00');
        $this->grantAt($this->ana, (string) $summerBefore->id, '2026-08-31 21:30:00');
        $this->grantAt($this->ana, (string) $summerAfter->id, '2026-08-31 22:30:00');

        // The night after them passes: others see what the nightly photo holds
        $this->travelTo('2026-12-01 03:30:00');
        $this->refreshRarity();
        $months = array_column($this->achievementsSection($this->render($this->ana, $this->luis))?->props['earned'] ?? [], 'grantedMonth', 'name');

        $this->assertSame(['Invierno' => '2026-12', 'Verano después' => '2026-09', 'Verano antes' => '2026-08'], $months);
    }

    public function test_accents_sort_where_people_expect_them(): void
    {
        foreach (['Zorro', 'Árbol', 'Barco'] as $name) {
            $this->grantAt($this->ana, (string) $this->achievement($this->countRule(), ['name' => $name])->id, '2026-11-10 18:00:00');
        }

        // The night passes: others see what the nightly photo holds
        $this->refreshRarity();
        $this->assertSame(['Árbol', 'Barco', 'Zorro'], array_column($this->achievementsSection($this->render($this->ana, $this->luis))?->props['earned'] ?? [], 'name'));
    }

    public function test_nobody_but_the_owner_gets_the_instant_even_on_the_server(): void
    {
        $this->grantAt($this->ana, (string) $this->achievement($this->countRule())->id, '2026-11-10 18:00:07');
        // The night passes: others see what the nightly photo holds
        $this->refreshRarity();
        $this->app->forgetScopedInstances();
        $showcase = app(AchievementShowcaseInterface::class);
        $anaId = strtolower((string) $this->ana->id);

        foreach ([null, strtolower((string) $this->luis->id)] as $viewerId) {
            $item = $showcase->earned($anaId, $viewerId)[0] ?? null;
            $this->assertNotNull($item);
            $this->assertNull($item->grantedAt);
            $this->assertNull($item->grantedOrder);
            $this->assertSame('2026-11', $item->grantedMonth);
        }

        $own = $showcase->earned($anaId, $anaId)[0] ?? null;
        $this->assertNotNull($own?->grantedAt);
        $this->assertNotNull($own->grantedOrder);
    }

    public function test_within_a_month_others_see_them_by_name_not_by_time(): void
    {
        $zeta = $this->achievement($this->countRule(), ['name' => 'Zeta']);
        $alfa = $this->achievement($this->countRule(), ['name' => 'Alfa']);
        $october = $this->achievement($this->countRule(), ['name' => 'Beta']);
        $this->grantAt($this->ana, (string) $alfa->id, '2026-11-02 18:00:00');
        $this->grantAt($this->ana, (string) $zeta->id, '2026-11-20 18:00:00');
        $this->grantAt($this->ana, (string) $october->id, '2026-10-30 18:00:00');

        // The night passes: others see what the nightly photo holds
        $this->refreshRarity();
        $others = $this->achievementsSection($this->render($this->ana, $this->luis))?->props['earned'] ?? [];
        $own = $this->achievementsSection($this->render($this->ana, $this->ana))?->props['earned'] ?? [];

        $this->assertSame(['Alfa', 'Zeta', 'Beta'], array_column($others, 'name'));
        $this->assertSame(['Zeta', 'Alfa', 'Beta'], array_column($own, 'name'));
    }

    public function test_a_grant_of_today_shows_to_others_only_after_the_next_nightly_photo(): void
    {
        $old = $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        $new = $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Gran ayuda']);
        $this->grantAt($this->ana, (string) $old->id, '2026-10-01 18:00:00');
        $this->refreshRarity();
        $this->travel(2)->hours();
        $this->assertNotNull(app(AchievementGranterInterface::class)->grant(new NewGrant((string) $this->ana->id, (string) $new->id, GrantSource::Manual, true)));

        foreach ([null, $this->luis] as $viewer) {
            $profile = $this->render($this->ana, $viewer);
            $this->assertSame(['Primera partida'], array_column($this->achievementsSection($profile)?->props['earned'] ?? [], 'name'));
            $this->assertSame(1, $this->countStat($profile)?->value);
        }

        // The owner sees it at once
        $own = $this->render($this->ana, $this->ana);
        $this->assertSame(['Gran ayuda', 'Primera partida'], array_column($this->achievementsSection($own)?->props['earned'] ?? [], 'name'));
        $this->assertSame(2, $this->countStat($own)?->value);

        // The next night
        $this->travelTo('2026-11-23 03:30:00');
        $this->refreshRarity();

        $profile = $this->render($this->ana, $this->luis);
        $this->assertSame(['Gran ayuda', 'Primera partida'], array_column($this->achievementsSection($profile)?->props['earned'] ?? [], 'name'));
        $this->assertSame(2, $this->countStat($profile)?->value);
    }

    public function test_without_a_photo_others_see_nothing_and_the_owner_everything(): void
    {
        $this->grantAt($this->ana, (string) $this->achievement($this->countRule(), ['name' => 'Primera partida'])->id, '2026-10-01 18:00:00');

        foreach ([null, $this->luis] as $viewer) {
            $profile = $this->render($this->ana, $viewer);
            $this->assertNull($this->achievementsSection($profile));
            $this->assertNull($this->countStat($profile));
        }

        $own = $this->render($this->ana, $this->ana);
        $this->assertSame(['Primera partida'], array_column($this->achievementsSection($own)?->props['earned'] ?? [], 'name'));
        $this->assertSame(1, $this->countStat($own)?->value);
    }

    public function test_a_revoke_shows_to_others_at_once(): void
    {
        $kept = $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        $revoked = $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Gran ayuda']);
        $this->grantAt($this->ana, (string) $kept->id, '2026-10-01 18:00:00');
        $grant = app(AchievementGranterInterface::class)->grant(new NewGrant((string) $this->ana->id, (string) $revoked->id, GrantSource::Manual, true));
        $this->assertNotNull($grant);
        $this->travelTo('2026-11-23 04:00:00');
        $this->refreshRarity();
        $this->assertSame(2, $this->countStat($this->render($this->ana, $this->luis))?->value);

        app(AchievementGranterInterface::class)->revoke($grant->id, (string) $this->person('Admin')->id, 'Concedido por error');

        $profile = $this->render($this->ana, $this->luis);
        $this->assertSame(['Primera partida'], array_column($this->achievementsSection($profile)?->props['earned'] ?? [], 'name'));
        $this->assertSame(1, $this->countStat($profile)?->value);
    }

    public function test_a_grant_in_the_photos_own_second_waits_for_the_next_night(): void
    {
        $old = $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        $same = $this->achievement($this->countRule(), ['name' => 'Justo a tiempo']);
        $this->grantAt($this->ana, (string) $old->id, '2026-10-01 18:00:00');
        // 03:30 in Madrid: the very second of the photo
        $this->grantAt($this->ana, (string) $same->id, '2026-11-22 02:30:00');
        $this->refreshRarity();

        $profile = $this->render($this->ana, $this->luis);
        $this->assertSame(['Primera partida'], array_column($this->achievementsSection($profile)?->props['earned'] ?? [], 'name'));
        $this->assertSame(1, $this->countStat($profile)?->value);
    }

    public function test_a_secret_the_viewer_holds_and_the_owner_got_today_is_neither_shown_nor_counted(): void
    {
        $first = $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        $secret = $this->achievement($this->countRule(), ['name' => 'El secreto', 'is_secret' => true]);
        $this->grantAt($this->ana, (string) $first->id, '2026-10-01 18:00:00');
        $this->grantAt($this->luis, (string) $secret->id, '2026-10-01 18:00:00');
        $this->refreshRarity();
        // This morning, after the photo
        $this->grantAt($this->ana, (string) $secret->id, '2026-11-22 09:00:00');

        $profile = $this->render($this->ana, $this->luis);
        $props = $this->achievementsSection($profile)?->props;

        $this->assertSame(['Primera partida'], array_column($props['earned'] ?? [], 'name'));
        $this->assertSame(0, $props['secretCount'] ?? null);
        $this->assertSame(1, $this->countStat($profile)?->value);
    }

    public function test_the_owners_featured_mark_follows_their_choice_now(): void
    {
        $old = $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        $new = $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Gran ayuda']);
        $this->grantAt($this->ana, (string) $old->id, '2026-10-01 18:00:00');
        $this->refreshRarity();
        $this->grantAt($this->ana, (string) $new->id, '2026-11-22 09:00:00');
        $this->choose($this->ana, featured: [(string) $new->id]);

        $own = array_column($this->achievementsSection($this->render($this->ana, $this->ana))?->props['earned'] ?? [], 'featured', 'name');
        $this->assertTrue($own['Gran ayuda'] ?? null);

        // Others have not seen it yet: never featured to them
        $others = array_column($this->achievementsSection($this->render($this->ana, $this->luis))?->props['earned'] ?? [], 'featured', 'name');
        $this->assertArrayNotHasKey('Gran ayuda', $others);
    }

    public function test_rarity_goes_out_in_whole_percents(): void
    {
        $third = $this->achievement($this->countRule(), ['name' => 'Un tercio']);
        $this->grantAt($this->ana, (string) $third->id, '2026-10-01 18:00:00');

        foreach ([$this->ana, $this->luis, $this->person('Marta')] as $person) {
            $this->factAt($person, '2026-10-01 18:00:00');
        }

        $this->refreshRarity();

        $this->assertSame([33], array_column($this->achievementsSection($this->render($this->ana, $this->luis))?->props['earned'] ?? [], 'rarity'));
    }

    public function test_the_owner_also_sees_what_is_left_with_their_progress(): void
    {
        $this->registerInMemorySource();
        $this->app->forgetScopedInstances();
        $ten = $this->achievement($this->countRule(threshold: 10), ['name' => 'Diez partidas']);
        $this->achievement(['version' => 1, 'metric' => 'manual'], ['name' => 'Gran ayuda']);
        $this->achievement($this->countRule('venue_booking'), ['name' => 'Sin fuente']);
        $this->achievement($this->countRule(), ['name' => 'Apagado', 'is_active' => false]);
        $this->achievement($this->countRule(threshold: 2), ['name' => 'El secreto', 'is_secret' => true]);
        foreach (range(1, 7) as $game) {
            $this->ledgerSubject(InMemoryActivitySource::TYPE, 'mesa-'.$game, 'game_session', [$this->recordedFact((string) $this->ana->id, when: '2026-10-0'.$game.' 18:00:00')]);
        }

        $props = $this->achievementsSection($this->render($this->ana, $this->ana))?->props;

        $this->assertNotNull($props);
        $this->assertSame(['Diez partidas', 'Gran ayuda', 'Logro secreto'], array_column($props['locked'], 'name'));
        $this->assertSame(['value' => 7, 'threshold' => 10, 'inMonths' => false], $props['locked'][0]['progress']);
        $this->assertNull($props['locked'][0]['season']);
        $this->assertTrue($props['locked'][1]['manual']);
        $this->assertNull($props['locked'][1]['progress']);
        // A secret keeps its rule and its id too: nothing that would hint at it
        $this->assertTrue($props['locked'][2]['masked']);
        $this->assertNull($props['locked'][2]['id']);
        $this->assertNull($props['locked'][2]['progress']);
        $this->assertSame((string) $ten->id, $props['locked'][0]['id']);
        $this->assertSame(['hidden' => false, 'chosen' => [], 'maxFeatured' => 3, 'updateUrl' => '/logros/miembros/'.$this->handleOf($this->ana)], $props['owner']);
    }

    public function test_progress_switched_off_shows_the_owner_only_what_they_got(): void
    {
        $this->achievement($this->countRule(threshold: 10), ['name' => 'Diez partidas']);
        $first = $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        $this->grantAt($this->ana, (string) $first->id);
        $this->settingsWith(['progress' => false]);

        $props = $this->achievementsSection($this->render($this->ana, $this->ana))?->props;

        $this->assertSame(['Primera partida'], array_column($props['earned'] ?? [], 'name'));
        $this->assertSame([], $props['locked'] ?? null);
    }

    public function test_rarity_switched_off_shows_none(): void
    {
        $first = $this->achievement($this->countRule());
        $this->grantAt($this->ana, (string) $first->id);
        $this->factAt($this->ana, '2026-10-01 18:00:00');
        $this->refreshRarity();
        $this->settingsWith(['rarity' => false]);

        $props = $this->achievementsSection($this->render($this->ana, $this->luis))?->props;

        $this->assertFalse($props['showsRarity'] ?? null);
        $this->assertSame([null], array_column($props['earned'], 'rarity'));
    }

    public function test_secrets_others_may_not_see_are_only_counted(): void
    {
        $first = $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        $secret = $this->achievement($this->countRule(), ['name' => 'El secreto', 'description' => 'Lo que pasó', 'icon' => 'crown', 'is_secret' => true]);
        $other = $this->achievement($this->countRule(), ['name' => 'Otro secreto', 'is_secret' => true]);
        $this->grantAt($this->ana, (string) $first->id, '2026-09-01 18:00:00');
        $this->grantAt($this->ana, (string) $secret->id, '2026-10-01 18:00:03');
        $this->grantAt($this->ana, (string) $other->id, '2026-10-02 18:00:00');
        $this->factAt($this->ana, '2026-10-01 18:00:00');
        $this->refreshRarity();
        $marta = $this->person('Marta');
        $this->grantAt($marta, (string) $secret->id);

        foreach ([$this->luis, null] as $viewer) {
            $props = $this->achievementsSection($this->render($this->ana, $viewer))?->props;
            $json = (string) json_encode($props);

            $this->assertSame(['Primera partida'], array_column($props['earned'] ?? [], 'name'));
            $this->assertSame(2, $props['secretCount'] ?? null);
            // Nothing about either secret travels: not its name, id, date, look or rarity
            $this->assertStringNotContainsString('Logro secreto', $json);
            $this->assertStringNotContainsString('pasó', $json);
            $this->assertStringNotContainsString((string) $secret->id, $json);
            $this->assertStringNotContainsString('18:00:03', $json);
            $this->assertStringNotContainsString('2026-10-0', $json);
        }

        // Whoever holds one sees it; the other stays counted
        $holder = $this->achievementsSection($this->render($this->ana, $marta))?->props;
        $this->assertSame(['El secreto', 'Primera partida'], array_column($holder['earned'] ?? [], 'name'));
        $this->assertSame(1, $holder['secretCount'] ?? null);

        $owner = $this->achievementsSection($this->render($this->ana, $this->ana))?->props;
        $this->assertSame(['Otro secreto', 'El secreto', 'Primera partida'], array_column($owner['earned'] ?? [], 'name'));
        $this->assertSame(0, $owner['secretCount'] ?? null);
    }

    public function test_someone_with_only_secrets_shows_how_many(): void
    {
        $this->grantAt($this->ana, (string) $this->achievement($this->countRule(), ['is_secret' => true])->id);

        // The night passes: others see what the nightly photo holds
        $this->refreshRarity();
        $props = $this->achievementsSection($this->render($this->ana, $this->luis))?->props;

        $this->assertSame([], $props['earned'] ?? null);
        $this->assertSame(1, $props['secretCount'] ?? null);
    }

    public function test_an_owner_who_is_not_active_sees_no_share_of_zero(): void
    {
        $first = $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        $this->grantAt($this->ana, (string) $first->id);
        $this->factAt($this->luis, '2026-10-01 18:00:00');
        $this->refreshRarity();

        foreach ([$this->ana, $this->luis] as $viewer) {
            $this->assertSame([null], array_column($this->achievementsSection($this->render($this->ana, $viewer))?->props['earned'] ?? [], 'rarity'));
        }
    }

    public function test_an_owner_who_must_verify_their_email_gets_no_controls(): void
    {
        $this->grantAt($this->ana, (string) $this->achievement($this->countRule())->id);
        $policy = $this->createStub(EmailVerificationPolicyInterface::class);
        $policy->method('mustVerify')->willReturn(true);
        $this->app->instance(EmailVerificationPolicyInterface::class, $policy);

        $props = $this->achievementsSection($this->render($this->ana, $this->ana))?->props;

        $this->assertIsArray($props);
        $this->assertArrayHasKey('owner', $props);
        $this->assertNull($props['owner']);
        $this->assertCount(1, $props['earned']);
    }

    public function test_a_secret_the_owner_features_shows_to_everybody(): void
    {
        $secret = $this->achievement($this->countRule(), ['name' => 'El secreto', 'is_secret' => true]);
        $this->grantAt($this->ana, (string) $secret->id);
        $this->choose($this->ana, featured: [(string) $secret->id]);

        // The night passes: others see what the nightly photo holds
        $this->refreshRarity();
        $earned = $this->achievementsSection($this->render($this->ana, $this->luis))?->props['earned'][0];

        $this->assertSame(['El secreto', true], [$earned['name'] ?? null, $earned['featured'] ?? null]);
    }

    public function test_achievements_hidden_by_their_owner_show_to_nobody_else(): void
    {
        $first = $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        $this->grantAt($this->ana, (string) $first->id);
        $this->choose($this->ana, hidden: true);

        foreach ([null, $this->luis] as $viewer) {
            $profile = $this->render($this->ana, $viewer);

            $this->assertNull($this->achievementsSection($profile));
            $this->assertNull($this->countStat($profile));
        }

        $own = $this->render($this->ana, $this->ana);
        $this->assertSame(['Primera partida'], array_column($this->achievementsSection($own)?->props['earned'] ?? [], 'name'));
        $this->assertTrue($this->achievementsSection($own)?->props['owner']['hidden']);
        $this->assertSame(1, $this->countStat($own)?->value);
    }

    public function test_the_count_counts_every_held_achievement_and_is_nothing_at_zero(): void
    {
        // The night passes: others see what the nightly photo holds
        $this->refreshRarity();
        $this->assertNull($this->countStat($this->render($this->ana, $this->luis)));

        foreach ([false, true] as $secret) {
            $this->grantAt($this->ana, (string) $this->achievement($this->countRule(), ['is_secret' => $secret])->id);
        }

        $stat = $this->countStat($this->render($this->ana, $this->luis));

        $this->assertSame(2, $stat?->value);
        $this->assertSame('/logros', $stat->url);
        $this->assertSame('Logros', $stat->label);
    }

    public function test_someone_with_nothing_has_no_section_for_others(): void
    {
        $this->achievement($this->countRule(threshold: 10));

        $this->assertNull($this->achievementsSection($this->render($this->ana, $this->luis)));
    }

    public function test_nothing_names_the_tables_or_events_behind_an_achievement(): void
    {
        $this->registerInMemorySource();
        $this->app->forgetScopedInstances();
        $first = $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        $this->ledgerSubject(InMemoryActivitySource::TYPE, 'mesa-secreta-del-viernes', 'game_session', [$this->recordedFact((string) $this->ana->id)]);
        $this->grantAt($this->ana, (string) $first->id);
        $this->achievement($this->countRule(threshold: 5), ['name' => 'Cinco partidas']);

        foreach ([$this->luis, $this->ana] as $viewer) {
            $json = (string) json_encode($this->achievementsSection($this->render($this->ana, $viewer))?->props);

            $this->assertStringNotContainsString('mesa-secreta', $json);
            $this->assertStringNotContainsString('rule', $json);
            $this->assertStringNotContainsString('points', $json);
        }
    }

    public function test_a_hidden_profile_draws_no_section_at_all(): void
    {
        $hidden = $this->person('Oculta', 'hidden');
        $this->grantAt($hidden, (string) $this->achievement($this->countRule(), ['name' => 'Primera partida'])->id);

        $this->actingAs($this->luis)->get('/miembros/'.$this->handleOf($hidden))->assertNotFound()->assertDontSee('Primera partida');

        // A guest may not see a profile for members only
        $this->app['auth']->forgetGuards();
        $this->get('/miembros/'.$this->handleOf($this->ana))->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function settingsWith(array $values): void
    {
        app(AchievementsSettingsInterface::class)->save(AchievementsSettings::fromArray($values));
        $this->app->forgetScopedInstances();
    }

    private function isoInMadrid(mixed $iso): string
    {
        $this->assertIsString($iso);

        return new \DateTimeImmutable($iso)->setTimezone(new \DateTimeZone('Europe/Madrid'))->format(DATE_ATOM);
    }
}
