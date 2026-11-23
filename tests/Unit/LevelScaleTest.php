<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Unit;

use InvalidArgumentException;
use Modules\Achievements\Domain\ValueObjects\LevelScale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LevelScaleTest extends TestCase
{
    public function test_points_reach_the_last_level_whose_threshold_they_meet(): void
    {
        $scale = new LevelScale([
            ['threshold' => 0, 'name' => 'Uno'],
            ['threshold' => 20, 'name' => 'Dos'],
            ['threshold' => 50, 'name' => 'Tres'],
        ]);

        $this->assertSame('Uno', $scale->levelFor(0)->name);
        $this->assertSame(1, $scale->levelFor(19)->number);
        $this->assertSame('Dos', $scale->levelFor(20)->name);
        $this->assertSame(3, $scale->levelFor(5000)->number);
        $this->assertSame('Dos', $scale->nextAfter(0)?->name);
        $this->assertSame(50, $scale->nextAfter(20)?->threshold);
        $this->assertNull($scale->nextAfter(50));
    }

    public function test_it_round_trips_through_its_stored_shape(): void
    {
        $stored = [['threshold' => 0, 'name' => 'Uno'], ['threshold' => 5, 'name' => 'Dos']];

        $this->assertSame($stored, LevelScale::tryFrom($stored)?->toArray());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notScales(): iterable
    {
        yield 'not a list' => [['a' => ['threshold' => 0, 'name' => 'Uno']]];
        yield 'one level' => [[['threshold' => 0, 'name' => 'Uno']]];
        yield 'first above zero' => [[['threshold' => 5, 'name' => 'Uno'], ['threshold' => 10, 'name' => 'Dos']]];
        yield 'not increasing' => [[['threshold' => 0, 'name' => 'Uno'], ['threshold' => 0, 'name' => 'Dos']]];
        yield 'blank name' => [[['threshold' => 0, 'name' => 'Uno'], ['threshold' => 10, 'name' => "  \u{202E} "]]];
        yield 'text threshold' => [[['threshold' => 0, 'name' => 'Uno'], ['threshold' => '10', 'name' => 'Dos']]];
        yield 'unknown key' => [[['threshold' => 0, 'name' => 'Uno', 'colour' => 'red'], ['threshold' => 10, 'name' => 'Dos']]];
        yield 'too high' => [[['threshold' => 0, 'name' => 'Uno'], ['threshold' => LevelScale::MAX_THRESHOLD + 1, 'name' => 'Dos']]];
        yield 'scalar' => ['niveles'];
    }

    #[DataProvider('notScales')]
    public function test_anything_that_is_not_a_scale_reads_as_none(mixed $stored): void
    {
        $this->assertNull(LevelScale::tryFrom($stored));
    }

    public function test_names_are_one_line_of_plain_text_cut_to_the_limit(): void
    {
        $scale = new LevelScale([
            ['threshold' => 0, 'name' => "  Primer\u{202E}  nivel\n"],
            ['threshold' => 1, 'name' => str_repeat('a', 60)],
        ]);

        $this->assertSame('Primer nivel', $scale->levels[0]->name);
        $this->assertSame(LevelScale::NAME_MAX_LENGTH, mb_strlen($scale->levels[1]->name));
    }

    public function test_too_many_levels_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LevelScale(array_map(static fn (int $n): array => ['threshold' => $n, 'name' => 'N'.$n], range(0, LevelScale::MAX_LEVELS)));
    }
}
