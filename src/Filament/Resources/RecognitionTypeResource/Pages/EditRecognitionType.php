<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources\RecognitionTypeResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Achievements\Filament\Resources\RecognitionTypeResource;

final class EditRecognitionType extends EditRecord
{
    protected static string $resource = RecognitionTypeResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [RecognitionTypeResource::deleteAction(DeleteAction::make())];
    }
}
