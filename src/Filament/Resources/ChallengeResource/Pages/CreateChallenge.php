<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources\ChallengeResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Achievements\Filament\Resources\ChallengeResource;
use Modules\Achievements\Filament\Support\ChallengeForm;

/**
 * Creating a challenge counts it at once, on the queue (the model's observer).
 */
final class CreateChallenge extends CreateRecord
{
    protected static string $resource = ChallengeResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        ChallengeForm::assertLongEnough($data);
        $actor = auth()->user()?->getAuthIdentifier();

        return ChallengeForm::intoRule($data) + ['created_by' => is_string($actor) ? $actor : null];
    }
}
