<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Unit;

use Modules\Achievements\Infrastructure\Profiles\ShowcasePresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The browser gets whole percents (0 for anything under 1 %, drawn «< 1 %»), never the exact share.
 */
final class ShownRarityTest extends TestCase
{
    /**
     * @return iterable<string, array{float|null, int|null}>
     */
    public static function shares(): iterable
    {
        yield 'unknown' => [null, null];
        yield 'nobody active' => [0.0, null];
        yield 'a tiny share' => [0.04, 0];
        yield 'just under one' => [0.99, 0];
        yield 'one' => [1.0, 1];
        yield 'a third' => [33.33, 33];
        yield 'half rounds up' => [12.5, 13];
        yield 'almost everybody' => [99.6, 100];
        yield 'everybody' => [100.0, 100];
    }

    #[DataProvider('shares')]
    public function test_a_share_goes_out_as_a_whole_percent(?float $share, ?int $shown): void
    {
        $this->assertSame($shown, ShowcasePresenter::shownRarity($share));
    }
}
