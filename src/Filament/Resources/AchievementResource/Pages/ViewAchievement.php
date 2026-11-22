<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources\AchievementResource\Pages;

use App\Filament\Concerns\HasKnowledgeGuideAction;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Modules\Achievements\Filament\Resources\AchievementResource;

/**
 * An achievement as whoever does not manage the catalogue sees it (a secret one only with
 * `achievements.manage`, AchievementPolicy::view()), with who holds it and its history below.
 */
final class ViewAchievement extends ViewRecord
{
    use HasKnowledgeGuideAction;

    protected static string $resource = AchievementResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_filter([
            EditAction::make(),
            $this->knowledgeGuideAction('achievements.panel.grants'),
        ]);
    }
}
