<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources\AchievementResource\Pages;

use App\Application\Services\TimezoneServiceInterface;
use App\Filament\Concerns\HasKnowledgeGuideAction;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Modules\Achievements\Domain\Challenges\ChallengeRewardRule;
use Modules\Achievements\Domain\Repositories\ChallengeRepositoryInterface;
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
        $data = AchievementRuleForm::intoRule($data, $this->achievement());
        $this->keepItAReward($data);

        return $data;
    }

    /**
     * The reward of a challenge still counted (#12) stays switched on, visible and granted by hand,
     * or its contributors would get a reward nobody may see, or one a rule hands out anyway.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function keepItAReward(array $data): void
    {
        $achievement = $this->achievement();
        $active = (bool) ($data['is_active'] ?? $achievement->is_active);
        $secret = (bool) ($data['is_secret'] ?? $achievement->is_secret);

        if (ChallengeRewardRule::allows($active, $secret, $data['rule'] ?? $achievement->getRawOriginal('rule'))) {
            return;
        }

        $today = app(TimezoneServiceInterface::class)->today()->format('Y-m-d');

        if (app(ChallengeRepositoryInterface::class)->rewardingCounted($achievement->id, $today) === []) {
            return;
        }

        $field = match (true) {
            ! $active => 'data.is_active',
            $secret => 'data.is_secret',
            default => 'data.'.AchievementRuleForm::METRIC,
        };

        throw ValidationException::withMessages([$field => __('achievements::messages.challenges.reward_in_use')]);
    }

    private function achievement(): AchievementModel
    {
        $record = $this->getRecord();

        assert($record instanceof AchievementModel);

        return $record;
    }
}
