<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources\ChallengeResource\Pages;

use App\Filament\Concerns\HasKnowledgeGuideAction;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Achievements\Filament\Resources\ChallengeResource;
use Modules\Achievements\Filament\Support\ChallengeForm;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\ChallengeModel;

/**
 * Changing what it counts, its days, who takes part or the reward counts it again (the model's
 * observer). Rewards already granted stay. A closed challenge is never edited (ChallengePolicy).
 */
final class EditChallenge extends EditRecord
{
    use HasKnowledgeGuideAction;

    protected static string $resource = ChallengeResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_filter([
            ChallengeResource::closeAction(Action::make(ChallengeResource::CLOSE_ACTION))
                ->after(fn () => $this->redirect(ChallengeResource::getUrl('index'))),
            ChallengeResource::deleteAction(DeleteAction::make()),
            $this->knowledgeGuideAction('achievements.panel.challenges'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ChallengeForm::fill($this->challenge()->getRawOriginal('rule')) + $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ChallengeForm::intoRule($data, $this->challenge());
    }

    private function challenge(): ChallengeModel
    {
        $record = $this->getRecord();
        assert($record instanceof ChallengeModel);

        return $record;
    }
}
