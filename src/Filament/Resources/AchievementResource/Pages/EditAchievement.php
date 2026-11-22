<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources\AchievementResource\Pages;

use App\Filament\Concerns\HasKnowledgeGuideAction;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Resources\Pages\EditRecord;
use Modules\Achievements\Filament\Resources\AchievementResource;
use Modules\Achievements\Filament\Support\AchievementRuleForm;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;

/**
 * Changing the rule, switching the achievement on or changing its secrecy evaluates it again for
 * everybody, silently (the model's observer). What was granted stays.
 */
final class EditAchievement extends EditRecord
{
    use HasKnowledgeGuideAction;

    protected static string $resource = AchievementResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_filter([
            AchievementResource::guardDeletion(DeleteAction::make())
                // Only offered while nobody holds it (AchievementPolicy::delete())
                ->modalDescription(__('achievements::messages.catalogue.delete.description'))
                ->schema([
                    Checkbox::make('confirmation')
                        ->label(__('achievements::messages.catalogue.delete.confirmation'))
                        ->accepted()
                        ->required(),
                ]),
            $this->knowledgeGuideAction('achievements.panel.catalogue'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return AchievementRuleForm::fill($this->achievement()->getRawOriginal('rule')) + $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return AchievementRuleForm::intoRule($data, $this->achievement());
    }

    private function achievement(): AchievementModel
    {
        $record = $this->getRecord();

        assert($record instanceof AchievementModel);

        return $record;
    }
}
