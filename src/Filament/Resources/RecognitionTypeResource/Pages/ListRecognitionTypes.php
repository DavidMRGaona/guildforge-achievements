<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources\RecognitionTypeResource\Pages;

use App\Filament\Concerns\HasKnowledgeGuideAction;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Achievements\Filament\Resources\RecognitionTypeResource;

final class ListRecognitionTypes extends ListRecords
{
    use HasKnowledgeGuideAction;

    protected static string $resource = RecognitionTypeResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_filter([
            CreateAction::make(),
            $this->knowledgeGuideAction('achievements.panel.recognitions'),
        ]);
    }
}
