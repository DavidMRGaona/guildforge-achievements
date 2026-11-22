<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Profiles;

use App\Application\Profiles\Services\ProfileSectionRegistryInterface;
use App\Application\Profiles\Services\ProfileStatRegistryInterface;
use App\Application\Services\EmailVerificationPolicyInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
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

    public function test_anybody_who_sees_the_profile_sees_the_earned_ones_with_date_and_rarity(): void
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
            $this->assertSame('2026-10-01T20:00:00+02:00', $this->isoInMadrid($props['earned'][0]['grantedAt']));
            $this->assertSame([50.0, 100.0], array_column($props['earned'], 'rarity'));
            $this->assertSame([], $props['locked']);
            $this->assertNull($props['owner']);
        }
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
