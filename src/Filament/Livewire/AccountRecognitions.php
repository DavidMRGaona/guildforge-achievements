<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Livewire;

use App\Application\Services\TimezoneServiceInterface;
use App\Filament\Actions\RowDetailsAction;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Modules\Achievements\Domain\Repositories\RecognitionRepositoryInterface;
use Modules\Achievements\Filament\Support\GrantDisplay;
use Modules\Achievements\Filament\Support\RecognitionModerationAccess;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\RecognitionModel;
use Modules\Achievements\Infrastructure\Recognitions\RecognitionNotices;

/**
 * «Reconocimientos» on the user page (#11): what the account gave and received, with the other
 * person (masked by the core's UserPolicy), the type and the month alone, never the game. Only for
 * RecognitionModerationAccess; a recognition is deleted from here with a dialog and a box to tick.
 */
final class AccountRecognitions extends TableWidget
{
    public const string NAME = 'achievements.account-recognitions';

    public const string DELETE_ACTION = 'deleteRecognition';

    #[Locked]
    public string $userId = '';

    protected int|string|array $columnSpan = 'full';

    /** Whether the page's account may be managed, worked out once per request (not a public property: never sent to the browser) */
    private ?bool $mayDeleteHere = null;

    /** @var array<string, bool> other account id => whether it may be managed, per request */
    private array $mayManage = [];

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
        $userId = $this->userId;

        return $table
            ->heading($this->text('title'))
            ->description($this->text('description'))
            ->query(fn (): Builder => RecognitionModel::query()
                ->where(fn (Builder $either): Builder => $either->where('giver_id', $this->userId)->orWhere('receiver_id', $this->userId))
                ->with(['type', 'giver', 'receiver']))
            ->columns([
                TextColumn::make('type.name')
                    ->label($this->text('fields.type'))
                    ->wrap(),

                TextColumn::make('direction')
                    ->label($this->text('fields.direction'))
                    ->state(static fn (RecognitionModel $record): string => strtolower($record->giver_id) === $userId ? 'given' : 'received')
                    ->formatStateUsing(fn (string $state): string => $this->text('directions.'.$state))
                    ->badge()
                    ->color(static fn (string $state): string => $state === 'given' ? 'gray' : 'success'),

                TextColumn::make('person')
                    ->label($this->text('fields.person'))
                    ->state(static fn (RecognitionModel $record): string => GrantDisplay::accountName(strtolower($record->giver_id) === $userId ? $record->receiver : $record->giver))
                    ->wrap()
                    ->visibleFrom('sm'),

                TextColumn::make('given_at')
                    ->label($this->text('fields.month'))
                    // Month and year only: the day would point at the game
                    ->formatStateUsing(static fn (mixed $state, RecognitionModel $record): string => app(TimezoneServiceInterface::class)->toDisplay($record->given_at)->format('m/Y'))
                    ->visibleFrom('md'),
            ])
            ->recordActions([
                ActionGroup::make([
                    RowDetailsAction::make(),
                    $this->deleteAction(),
                ]),
            ])
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByDesc('given_at')->orderByDesc('id'))
            ->emptyStateHeading($this->text('empty'))
            ->paginated([10, 25, 50]);
    }

    private function deleteAction(): Action
    {
        return Action::make(self::DELETE_ACTION)
            ->label($this->text('delete.label'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (RecognitionModel $record): bool => $this->mayDelete($record))
            // Filament asks again when the action runs, on the server
            ->authorize(fn (RecognitionModel $record): bool => RecognitionModerationAccess::allowsDeletingRecord($this->userId, $record))
            ->requiresConfirmation()
            ->modalHeading($this->text('delete.heading'))
            ->modalDescription($this->text('delete.description'))
            ->schema([
                Checkbox::make('confirmation')
                    ->label($this->text('delete.confirmation'))
                    ->accepted()
                    ->required(),
            ])
            ->action(function (RecognitionModel $record): void {
                // Once more right before deleting, with the record as it is now
                abort_unless(RecognitionModerationAccess::allowsDeletingRecord($this->userId, $record), 403);

                $deleted = app(RecognitionRepositoryInterface::class)->delete($record->id);

                if ($deleted) {
                    app(RecognitionNotices::class)->forget([strtolower($record->id)]);
                }
                $actor = auth()->user();

                if ($deleted) {
                    Log::info('Recognition deleted from the panel', [
                        'actor_id' => $actor instanceof UserModel ? $actor->id : null,
                        'user_id' => $this->userId,
                    ]);
                }

                Notification::make()->title($this->text($deleted ? 'delete.done' : 'delete.gone'))->success()->send();
            });
    }

    /**
     * The same rule as RecognitionModerationAccess::allowsDeletingRecord(), remembered for the
     * request: the page's account once, each other person once, from the loaded relations.
     */
    private function mayDelete(RecognitionModel $record): bool
    {
        if (! RecognitionModerationAccess::isOnPage($this->userId, $record)) {
            return false;
        }

        $this->mayDeleteHere ??= RecognitionModerationAccess::allowsDeleting($this->userId);

        if (! $this->mayDeleteHere) {
            return false;
        }

        $other = strtolower((string) RecognitionModerationAccess::otherOf($this->userId, $record)?->getKey());

        return $this->mayManage[$other] ??= RecognitionModerationAccess::mayManageOther($this->userId, $record);
    }

    private function ensureAccess(): void
    {
        abort_unless(RecognitionModerationAccess::allows($this->userId), 403);
    }

    private function text(string $key): string
    {
        return (string) __('achievements::messages.recognitions.panel.'.$key);
    }
}
