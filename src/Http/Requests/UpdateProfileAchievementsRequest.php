<?php

declare(strict_types=1);

namespace Modules\Achievements\Http\Requests;

use App\Http\Requests\Profiles\OwnsProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Achievements\Application\Services\AchievementShowcaseInterface;
use Modules\Achievements\Domain\ValueObjects\AchievementPreferences;

/**
 * The owner's choices about their achievements, from the profile section: whether others see them
 * and which ones are featured. Only the owner of the profile at `{handle}` gets this far (OwnsProfile,
 * the core's editor rule, before anything is validated); a featured achievement must be one they hold.
 */
final class UpdateProfileAchievementsRequest extends FormRequest
{
    use OwnsProfile;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hidden' => ['required', 'boolean'],
            'featured' => ['present', 'array', 'list', 'max:'.AchievementPreferences::MAX_FEATURED],
            'featured.*' => ['required', 'string', 'distinct', 'max:36'],
            // «Aparecer en la clasificación» (#10): left out, the stored choice stays
            'leaderboard' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $showcase = $this->container->make(AchievementShowcaseInterface::class);
                $held = [];

                foreach ($showcase->earned($this->ownerId(), $this->ownerId()) as $item) {
                    $held[strtolower($item->achievement->id)] = true;
                }

                foreach ($this->featuredIds() as $id) {
                    if (! isset($held[$id])) {
                        $validator->errors()->add('featured', __('achievements::messages.profile.featured.not_held'));

                        return;
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'featured.max' => __('achievements::messages.profile.featured.too_many', ['max' => AchievementPreferences::MAX_FEATURED]),
        ];
    }

    public function hidden(): bool
    {
        return $this->boolean('hidden');
    }

    /**
     * The leaderboard choice sent, or null when it was not (it stays as it was).
     */
    public function leaderboard(): ?bool
    {
        return $this->has('leaderboard') ? $this->boolean('leaderboard') : null;
    }

    /**
     * @return list<string>
     */
    public function featuredIds(): array
    {
        $featured = $this->input('featured', []);

        return AchievementPreferences::normalise(is_array($featured) ? $featured : []);
    }
}
