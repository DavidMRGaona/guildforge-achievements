<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Unit;

use Modules\Achievements\Domain\Enums\GrantSource;
use Modules\Achievements\Domain\Rules\AnnouncementRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnnouncementRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{GrantSource, bool, float|null, bool}>
     */
    public static function grants(): iterable
    {
        yield 'rare rule grant' => [GrantSource::Rule, false, 9.99, true];
        yield 'at the threshold' => [GrantSource::Rule, false, 10.0, false];
        yield 'common' => [GrantSource::Rule, false, 50.0, false];
        yield 'held by nobody active yet' => [GrantSource::Rule, false, 0.0, true];
        yield 'unknown rarity' => [GrantSource::Rule, false, null, false];
        yield 'rare manual grant' => [GrantSource::Manual, false, 1.0, true];
        yield 'silent' => [GrantSource::Rule, true, 1.0, false];
        yield 'bulk, however rare' => [GrantSource::Bulk, false, 1.0, false];
        yield 'challenge, however common' => [GrantSource::Challenge, false, 90.0, true];
        yield 'challenge with unknown rarity' => [GrantSource::Challenge, false, null, true];
        yield 'silent challenge' => [GrantSource::Challenge, true, null, false];
    }

    #[DataProvider('grants')]
    public function test_it_announces_rare_and_challenge_grants_only(GrantSource $source, bool $silent, ?float $share, bool $announced): void
    {
        $this->assertSame($announced, AnnouncementRule::announces($source, $silent, $share, 10.0));
    }
}
