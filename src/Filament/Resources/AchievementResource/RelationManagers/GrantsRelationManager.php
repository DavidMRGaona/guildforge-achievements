<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources\AchievementResource\RelationManagers;

use App\Filament\Actions\RowDetailsAction;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Actions\ActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Modules\Achievements\Filament\Support\GrantActions;
use Modules\Achievements\Filament\Support\GrantDisplay;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementGrantModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;

/**
 * «Personas que lo tienen»: the holders of an achievement, seen with AchievementPolicy::viewGrants().
 * From here, with `achievements.grant`: grant it to one account, grant it in bulk, and revoke it.
 * An account the person may not see (UserPolicy::view()) shows as «Cuenta oculta» and cannot be
 * revoked from; searching by name is only offered with `users.view_any`.
 */
final class GrantsRelationManager extends RelationManager
{
    protected static string $relationship = 'grants';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $viewer = Auth::user();

        return $viewer instanceof UserModel && $ownerRecord instanceof AchievementModel && $viewer->can('viewGrants', $ownerRecord);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return (string) __('achievements::messages.grants.holders.title');
    }

    /**
     * Its actions are its own and authorized one by one, also on the view page.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $achievement = fn (): AchievementModel => $this->achievement();

        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['user', 'actor', 'achievement']))
            ->columns([
                TextColumn::make('account')
                    ->label($this->text('fields.account'))
                    ->state(static fn (AchievementGrantModel $record): string => GrantDisplay::accountName($record->user))
                    ->searchable(
                        condition: GrantDisplay::searchesAccounts(),
                        query: static fn (Builder $query, string $search): Builder => $query->whereHas(
                            'user',
                            static function (Builder $user) use ($search): void {
                                $pattern = '%'.addcslashes(mb_strtolower($search), '!%_').'%';
                                $user->where(static fn (Builder $names): Builder => $names
                                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                                    ->orWhereRaw("LOWER(COALESCE(display_name, '')) LIKE ? ESCAPE '!'", [$pattern]));
                            },
                        ),
                    )
                    ->wrap(),

                TextColumn::make('source')
                    ->label($this->text('fields.source'))
                    ->formatStateUsing(static fn (string $state): string => GrantDisplay::sourceLabel($state))
                    ->color(static fn (string $state): string => GrantDisplay::sourceColour($state))
                    ->badge()
                    ->visibleFrom('sm'),

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
                GrantActions::grantToAccount($achievement),
                GrantActions::bulkGrant($achievement),
            ])
            ->recordActions([
                ActionGroup::make([
                    RowDetailsAction::make(),
                    GrantActions::revoke(),
                ]),
            ])
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByDesc('granted_at')->orderByDesc('id'))
            ->emptyStateHeading($this->text('holders.empty'));
    }

    private function achievement(): AchievementModel
    {
        $record = $this->getOwnerRecord();

        assert($record instanceof AchievementModel);

        return $record;
    }

    private function text(string $key): string
    {
        return (string) __('achievements::messages.grants.'.$key);
    }
}
