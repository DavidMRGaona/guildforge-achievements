<?php

declare(strict_types=1);

namespace Modules\Achievements\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;

/**
 * The choices of the account settings' «Logros» tab (achievements#6). Either may be left out: what is
 * not sent stays as it was. Announcing is refused while announcements are off.
 */
final class UpdateAccountAchievementsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'announceRare' => ['sometimes', 'boolean'],
            'inLeaderboard' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Announcing is not offered while announcements are off: asking for it then is refused, never
     * answered as saved.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->has('announceRare') && ! $this->container->make(AchievementsSettingsInterface::class)->announcementsEnabled()) {
                    $validator->errors()->add('announceRare', __('achievements::notifications.account.announcements_off'));
                }
            },
        ];
    }

    /** Null when not sent: it stays as it is */
    public function announceRare(): ?bool
    {
        return $this->has('announceRare') ? $this->boolean('announceRare') : null;
    }

    /** Null when not sent: it stays as it is */
    public function inLeaderboard(): ?bool
    {
        return $this->has('inLeaderboard') ? $this->boolean('inLeaderboard') : null;
    }
}
