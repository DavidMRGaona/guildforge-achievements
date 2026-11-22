<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Feature\Catalogue;

use Illuminate\Support\Facades\Bus;
use Modules\Achievements\Application\DTOs\AchievementViewDTO;
use Modules\Achievements\Application\Services\AchievementCatalogueInterface;
use Modules\Achievements\Application\Services\AchievementGranterInterface;
use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\ValueObjects\NewGrant;
use Modules\Achievements\Jobs\EvaluateAchievementsJob;
use Modules\Achievements\Tests\Support\BuildsAchievements;
use Modules\Achievements\Tests\Support\BuildsActivityFacts;
use Tests\Support\Modules\ModuleTestCase;

final class AchievementCatalogueTest extends ModuleTestCase
{
    use BuildsAchievements;
    use BuildsActivityFacts;

    protected ?string $moduleName = 'achievements';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutStarterCatalogue();

        Bus::fake([EvaluateAchievementsJob::class]);
    }

    public function test_a_secret_achievement_is_masked_for_whoever_does_not_hold_it(): void
    {
        $ana = $this->account(self::ANA);
        $luis = $this->account(self::LUIS);
        $secret = $this->achievement($this->countRule(), ['name' => 'Dragón dormido', 'description' => 'Despierta al dragón', 'icon' => 'flame', 'colour' => 'red', 'is_secret' => true, 'image_public_id' => 'achievements/badges/2026/11/dragon.png']);
        $this->app->make(AchievementGranterInterface::class)->grant(new NewGrant($ana, $secret->id, GrantSource::Manual, silent: true));

        foreach ([null, $luis] as $viewer) {
            $view = $this->only($viewer);
            $this->assertTrue($view->masked);
            $this->assertSame('Logro secreto', $view->name);
            $this->assertSame('sparkles', $view->icon);
            $this->assertSame('slate', $view->colour);
            $this->assertNull($view->imagePublicId);
            $this->assertNull($view->rule);
            $this->assertSame(0, $view->points);
            $this->assertStringNotContainsString('dragón', strtolower($view->description));
        }

        $held = $this->only(strtoupper($ana));
        $this->assertFalse($held->masked);
        $this->assertTrue($held->held);
        $this->assertSame('Dragón dormido', $held->name);
        $this->assertNotNull($held->rule);
    }

    public function test_an_achievement_switched_off_is_listed_only_for_whoever_holds_it(): void
    {
        $ana = $this->account(self::ANA);
        $off = $this->achievement($this->countRule(), ['is_active' => false]);

        $this->assertSame([], $this->catalogue()->forViewer(null));

        $this->app->make(AchievementGranterInterface::class)->grant(new NewGrant($ana, $off->id, GrantSource::Manual, silent: true));

        $this->assertSame($off->id, $this->only($ana)->id);
    }

    public function test_masked_achievements_sort_by_the_name_shown(): void
    {
        $secret = $this->achievement($this->countRule(), ['name' => 'Zzz secreto', 'is_secret' => true]);
        $visible = $this->achievement($this->countRule(), ['name' => 'Mmm visible']);

        $ids = array_map(static fn (AchievementViewDTO $view): string => $view->id, $this->catalogue()->forViewer(null));

        $this->assertSame([$secret->id, $visible->id], $ids);
    }

    private function only(?string $viewer): AchievementViewDTO
    {
        $views = $this->catalogue()->forViewer($viewer);
        $this->assertCount(1, $views);

        return $views[0];
    }

    private function catalogue(): AchievementCatalogueInterface
    {
        return $this->app->make(AchievementCatalogueInterface::class);
    }
}
