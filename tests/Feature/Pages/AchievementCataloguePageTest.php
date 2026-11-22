<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Pages;

use App\Infrastructure\Navigation\Persistence\Eloquent\Models\MenuItemModel;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Achievements\Application\DTOs\AchievementsSettings;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Database\Seeders\AchievementsMenuLinkSeeder;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsShowcase;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * `/logros`: the catalogue with the rarity of each achievement, for everybody; secrets keep their
 * name, look and rarity from whoever does not hold them. A link in the header menu leads there.
 */
final class AchievementCataloguePageTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsShowcase;
    use UsesUtcStorage;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->withoutStarterCatalogue();
        $this->travelTo('2026-11-22 10:00:00');
    }

    public function test_a_guest_sees_the_catalogue_with_rarity_and_secrets_masked(): void
    {
        $first = $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        $secret = $this->achievement($this->countRule(), ['name' => 'El secreto', 'description' => 'Lo que pasó', 'is_secret' => true]);
        $this->achievement($this->countRule(), ['name' => 'Apagado', 'is_active' => false]);
        $ana = $this->person('Ana');
        $luis = $this->person('Luis');
        $this->factAt($ana, '2026-10-01 18:00:00');
        $this->factAt($luis, '2026-10-01 18:00:00');
        $this->grantAt($ana, (string) $first->id);
        $this->grantAt($ana, (string) $secret->id);
        $this->refreshRarity();

        $this->get('/logros')
            ->assertOk()
            ->assertDontSee('Lo que pasó')
            ->assertDontSee('Apagado')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Achievements/Index', false)
                ->where('showsRarity', true)
                ->where('signedIn', false)
                ->has('achievements', 2)
                ->where('achievements.0.name', 'Logro secreto')
                ->where('achievements.0.masked', true)
                ->where('achievements.0.rarity', null)
                ->where('achievements.1.name', 'Primera partida')
                ->where('achievements.1.rarity', 50.0)
                ->where('achievements.1.held', false)
                ->missing('achievements.1.rule')
                ->where('seo.title', fn (string $title): bool => str_contains($title, 'Logros')));
    }

    public function test_a_holder_sees_their_secret_and_what_they_hold(): void
    {
        $secret = $this->achievement($this->countRule(), ['name' => 'El secreto', 'is_secret' => true]);
        $ana = $this->person('Ana');
        $this->grantAt($ana, (string) $secret->id);

        $this->actingAs($ana)->get('/logros')->assertInertia(fn (Assert $page): Assert => $page
            ->where('signedIn', true)
            ->where('achievements.0.name', 'El secreto')
            ->where('achievements.0.held', true));
    }

    public function test_rarity_switched_off_is_not_shown(): void
    {
        $this->achievement($this->countRule(), ['name' => 'Primera partida']);
        app(AchievementsSettingsInterface::class)->save(AchievementsSettings::fromArray(['rarity' => false]));

        $this->get('/logros')->assertInertia(fn (Assert $page): Assert => $page
            ->where('showsRarity', false)
            ->where('achievements.0.rarity', null));
    }

    public function test_the_menu_link_is_added_once_as_the_modules_own(): void
    {
        (new AchievementsMenuLinkSeeder)->run();
        (new AchievementsMenuLinkSeeder)->run();

        $link = MenuItemModel::query()->where('route', AchievementsMenuLinkSeeder::ROUTE)->sole();
        $this->assertSame('achievements', $link->module);
        $this->assertSame('Logros', $link->label);
        $this->assertSame('header', $link->location->value);
    }
}
