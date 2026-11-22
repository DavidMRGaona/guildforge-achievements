<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Unit;

use App\Application\Profiles\ProfileBadgeIcons;
use App\Domain\Profiles\ProfileBadgeColour;
use PHPUnit\Framework\TestCase;

/**
 * The browser draws achievements with its own copy of the core's badge icons and colours (the
 * core's map is not part of its public frontend API): both lists stay the core's.
 */
final class BadgeLookTest extends TestCase
{
    private const string FILE = __DIR__.'/../../resources/js/utils/badgeLook.ts';

    public function test_the_icons_are_the_cores_badge_icons(): void
    {
        $this->assertSame($this->sorted(ProfileBadgeIcons::NAMES), $this->sorted($this->keysOf('badgeIcons')));
    }

    public function test_the_colours_are_the_cores_badge_colours(): void
    {
        $this->assertSame(
            $this->sorted(array_map(static fn (ProfileBadgeColour $colour): string => $colour->value, ProfileBadgeColour::cases())),
            $this->sorted($this->keysOf('badgeColours')),
        );
    }

    /**
     * @return list<string>
     */
    private function keysOf(string $constant): array
    {
        $source = (string) file_get_contents(self::FILE);
        $this->assertSame(1, preg_match('/export const '.$constant.': Record<string, [^>]+> = \{(.*?)\n\};/s', $source, $match));
        preg_match_all("/^\s*'?([a-z-]+)'?:/m", $match[1], $keys);

        return $keys[1];
    }

    /**
     * @param  array<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $values = array_values($values);
        sort($values);

        return $values;
    }
}
