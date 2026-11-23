<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources\ChallengeResource\Pages;

use App\Filament\Concerns\HasKnowledgeGuideAction;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Achievements\Filament\Resources\ChallengeResource;

final class ListChallenges extends ListRecords
{
    use HasKnowledgeGuideAction;

    protected static string $resource = ChallengeResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_filter([
            CreateAction::make(),
            $this->knowledgeGuideAction('achievements.panel.challenges'),
        ]);
    }
}
