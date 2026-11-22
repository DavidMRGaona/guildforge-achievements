<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources\AchievementResource\Pages;

use App\Filament\Concerns\HasKnowledgeGuideAction;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Achievements\Filament\Resources\AchievementResource;

final class ListAchievements extends ListRecords
{
    use HasKnowledgeGuideAction;

    protected static string $resource = AchievementResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_filter([
            CreateAction::make(),
            $this->knowledgeGuideAction('achievements.panel.catalogue'),
        ]);
    }
}
