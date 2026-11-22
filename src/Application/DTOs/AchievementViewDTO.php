<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use Modules\Achievements\Domain\Rules\AchievementRule;

/**
 * An achievement as one person may see it. A secret achievement that person does not hold comes
 * `masked`: generic name, description, icon and colour, no image and no rule, so nothing tells it
 * apart from another secret one but its id.
 */
final readonly class AchievementViewDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $icon,
        public string $colour,
        public ?string $imagePublicId,
        public int $points,
        public bool $secret,
        public bool $held,
        public bool $masked,
        public ?AchievementRule $rule,
    ) {}
}
