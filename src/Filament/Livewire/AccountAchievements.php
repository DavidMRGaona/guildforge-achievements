<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Livewire;

use App\Filament\Actions\RowDetailsAction;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Modules\Achievements\Filament\Support\AccountGrantsAccess;
use Modules\Achievements\Filament\Support\GrantActions;
use Modules\Achievements\Filament\Support\GrantDisplay;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementGrantModel;

/**
 * «Logros» on the user page (drawn by AccountAchievementsHook): what the account holds, with
 * «Conceder» and, per grant, «Retirar». Access is asked again on every request
 * (AccountGrantsAccess), and each action is authorized by AchievementPolicy.
 */
final class AccountAchievements extends TableWidget
{
    public const string NAME = 'achievements.account-achievements';

    #[Locked]
    public string $userId = '';

    protected int|string|array $columnSpan = 'full';

    public function mount(string $userId): void
    {
        $this->userId = strtolower($userId);
        $this->ensureAccess();
    }

    public function hydrate(): void
    {
        $this->ensureAccess();
    }

    public function table(Table $table): Table
    {
        $userId = fn (): string => $this->userId;

        return $table
            ->heading($this->text('account.title'))
            ->description($this->text('account.description'))
            ->query(fn (): Builder => AchievementGrantModel::query()
                ->where('user_id', $this->userId)
                ->with(['achievement', 'actor']))
            ->columns([
                TextColumn::make('achievement')
                    ->label($this->text('fields.achievement'))
                    ->state(static fn (AchievementGrantModel $record): string => GrantDisplay::achievementName($record->achievement))
                    ->wrap(),

                TextColumn::make('source')
                    ->label($this->text('fields.source'))
                    ->formatStateUsing(static fn (string $state): string => GrantDisplay::sourceLabel($state))
                    ->color(static fn (string $state): string => GrantDisplay::sourceColour($state))
                    ->badge()
                    ->visibleFrom('sm'),

                TextColumn::make('season')
                    ->label($this->text('fields.season'))
                    ->formatStateUsing(static fn (?string $state): ?string => GrantDisplay::seasonLabel($state))
                    ->placeholder('—')
                    ->sortable()
                    ->visibleFrom('xl'),

                TextColumn::make('granted_at')
                    ->label($this->text('fields.granted_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->visibleFrom('md'),

                TextColumn::make('actor')
                    ->label($this->text('fields.actor'))
                    ->state(static fn (AchievementGrantModel $record): string => GrantDisplay::actorName($record->actor, $record->source))
                    ->wrap()
                    ->visibleFrom('xl'),
            ])
            ->headerActions([
                GrantActions::grantAchievementTo($userId),
            ])
            ->recordActions([
                ActionGroup::make([
                    RowDetailsAction::make(),
                    GrantActions::revoke(),
                ]),
            ])
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByDesc('granted_at')->orderByDesc('id'))
            ->emptyStateHeading($this->text('account.empty'))
            ->paginated([10, 25, 50]);
    }

    private function ensureAccess(): void
    {
        abort_unless(AccountGrantsAccess::allows($this->userId), 403);
    }

    private function text(string $key): string
    {
        return (string) __('achievements::messages.grants.'.$key);
    }
}
