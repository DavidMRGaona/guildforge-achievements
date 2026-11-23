<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources\ChallengeResource\Pages;

use App\Filament\Concerns\HasKnowledgeGuideAction;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Modules\Achievements\Domain\Challenges\ChallengeRewardRule;
use Modules\Achievements\Domain\Entities\Achievement;
use Modules\Achievements\Domain\Repositories\AchievementRepositoryInterface;
use Modules\Achievements\Filament\Resources\ChallengeResource;
use Modules\Achievements\Filament\Support\ChallengeForm;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\ChallengeModel;

/**
 * Changing what it counts, its days, who takes part or the reward counts it again (the model's
 * observer). Rewards already granted stay. A closed challenge is never edited, and a complete one
 * only changes its name and description (ChallengePolicy).
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
        $user = auth()->user();

        // Complete: only the name and the description change, whatever the form sent
        if (! $user instanceof UserModel || ! $user->can('changeWhatCounts', $this->challenge())) {
            return array_diff_key($data, array_flip(ChallengeForm::lockedFields()));
        }

        $data = ChallengeForm::intoRule($data, $this->challenge());
        $this->keepTheRewardUsable($data);

        return $data;
    }

    /**
     * Changing what it counts or its days may bring a challenge back to being counted (one past its
     * late facts' days, say): its reward must then still be one (ChallengeRewardRule), however long
     * nobody watched over it.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function keepTheRewardUsable(array $data): void
    {
        $record = $this->challenge();
        $changed = ($data['rule'] ?? null) != $record->rule
            || $this->day($data['starts_on'] ?? null) !== $record->starts_on->format('Y-m-d')
            || $this->day($data['ends_on'] ?? null) !== $record->ends_on->format('Y-m-d')
            || ($data['audience'] ?? $record->audience) !== $record->audience
            || ($data['reward_achievement_id'] ?? $record->reward_achievement_id) !== $record->reward_achievement_id;

        if (! $changed) {
            return;
        }

        $rewardId = (string) ($data['reward_achievement_id'] ?? $record->reward_achievement_id);
        $reward = app(AchievementRepositoryInterface::class)->find($rewardId);

        if (! $reward instanceof Achievement || ! ChallengeRewardRule::isUsable($reward)) {
            throw ValidationException::withMessages(['data.reward_achievement_id' => __('achievements::messages.challenges.reward_not_usable')]);
        }
    }

    private function day(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    private function challenge(): ChallengeModel
    {
        $record = $this->getRecord();
        assert($record instanceof ChallengeModel);

        return $record;
    }
}
