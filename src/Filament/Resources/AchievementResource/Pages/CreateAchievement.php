<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources\AchievementResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Achievements\Filament\Resources\AchievementResource;
use Modules\Achievements\Filament\Support\AchievementRuleForm;

/**
 * Creating an achievement evaluates it for everybody, silently, on the queue (the model's observer).
 */
final class CreateAchievement extends CreateRecord
{
    protected static string $resource = AchievementResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        AchievementResource::checkImageFolder($data);

        return AchievementRuleForm::intoRule($data);
    }
}
