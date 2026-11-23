<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Recognitions;

use App\Domain\Profiles\ProfileBadgeColour;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Achievements\Application\Services\StarterRecognitionTypesInterface;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\RecognitionTypeModel;

/**
 * The texts live in `lang/<locale>/starter.php` under `recognitions`, copied when created.
 */
final readonly class StarterRecognitionTypes implements StarterRecognitionTypesInterface
{
    /**
     * Starter key => [icon, colour], in the order they are created.
     *
     * @var array<string, array{string, ProfileBadgeColour}>
     */
    private const array TYPES = [
        'great_storytelling' => ['scroll-text', ProfileBadgeColour::Violet],
        'good_companion' => ['handshake', ProfileBadgeColour::Sky],
        'helps_newcomers' => ['sprout', ProfileBadgeColour::Green],
        'great_atmosphere' => ['flame', ProfileBadgeColour::Amber],
    ];

    public function install(): array
    {
        $created = [];

        foreach (self::TYPES as $key => [$icon, $colour]) {
            if (RecognitionTypeModel::query()->where('starter_key', $key)->exists()) {
                continue;
            }

            try {
                new RecognitionTypeModel()->forceFill([
                    'name' => (string) __('achievements::starter.recognitions.'.$key),
                    'icon' => $icon,
                    'colour' => $colour->value,
                    'is_active' => true,
                    'starter_key' => $key,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                // Another install created it meanwhile
                continue;
            }

            $created[] = $key;
        }

        return $created;
    }
}
