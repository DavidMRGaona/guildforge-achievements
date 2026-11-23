<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources\RecognitionTypeResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Achievements\Filament\Resources\RecognitionTypeResource;

final class CreateRecognitionType extends CreateRecord
{
    protected static string $resource = RecognitionTypeResource::class;
}
