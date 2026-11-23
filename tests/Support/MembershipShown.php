<?php

declare(strict_types=1);

namespace Modules\Achievements\Tests\Support;

use App\Application\Profiles\Services\ProfileMembershipDisplayInterface;

/**
 * Whether profiles show the membership badge, as a test says: the same answer for everybody.
 */
final readonly class MembershipShown implements ProfileMembershipDisplayInterface
{
    public function __construct(
        private bool $shown,
    ) {}

    public function showsMembership(array $userIds): array
    {
        return array_fill_keys(array_map(strtolower(...), $userIds), $this->shown);
    }
}
