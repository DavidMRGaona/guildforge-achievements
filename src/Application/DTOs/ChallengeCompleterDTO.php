<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

/**
 * Somebody who completed a challenge, as the viewer may see them: the public name and the profile
 * from the core's card (never the account id, name or email).
 */
final readonly class ChallengeCompleterDTO
{
    public function __construct(
        public string $publicName,
        public string $url,
        public ?string $avatarUrl,
    ) {}
}
