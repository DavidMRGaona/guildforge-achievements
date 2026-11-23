<?php

declare(strict_types=1);

namespace Modules\Achievements\Application\DTOs;

use App\Application\Profiles\ProfileBadgeIcons;
use App\Domain\Profiles\ProfileBadgeColour;
use Modules\Achievements\Domain\Recognitions\RecognitionType;

/**
 * A recognition type for the browser: an icon or colour outside the core's lists becomes the
 * default, so the page never draws something it does not know.
 */
final readonly class RecognitionTypeViewDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $icon,
        public string $colour,
    ) {}

    public static function of(RecognitionType $type): self
    {
        return new self(
            $type->id,
            $type->name,
            ProfileBadgeIcons::isKnown($type->icon) ? $type->icon : 'award',
            ProfileBadgeColour::tryFrom($type->colour) instanceof ProfileBadgeColour ? $type->colour : ProfileBadgeColour::Slate->value,
        );
    }

    /**
     * @return array{id: string, name: string, icon: string, colour: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'icon' => $this->icon, 'colour' => $this->colour];
    }
}
