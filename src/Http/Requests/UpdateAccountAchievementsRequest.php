<?php

declare(strict_types=1);

namespace Modules\Achievements\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The choices of the account settings' «Logros» tab (achievements#6). Either may be left out: what is
 * not sent stays as it was.
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
