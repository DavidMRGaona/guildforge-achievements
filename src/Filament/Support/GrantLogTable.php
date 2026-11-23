<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Support;

use App\Filament\Actions\RowDetailsAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementGrantLogModel;

/**
 * The grant history as a read-only table, on the achievement page (by account) and on the account
 * page (by achievement). Nothing in it can be changed: its only row action shows the hidden columns.
 */
final class GrantLogTable
{
    public static function configure(Table $table, bool $withAchievement, bool $withAccount): Table
    {
        $columns = [
            TextColumn::make('created_at')
                ->label(self::text('fields.when'))
                ->dateTime('d/m/Y H:i')
                ->sortable()
                // On a phone the action and who fit; the date is in «Ver detalles»
                ->visibleFrom('sm'),

            TextColumn::make('action')
                ->label(self::text('fields.action'))
                ->formatStateUsing(static fn (string $state): string => GrantDisplay::actionLabel($state))
                ->color(static fn (string $state): string => GrantDisplay::actionColour($state))
                ->badge(),
        ];

        if ($withAchievement) {
            $columns[] = TextColumn::make('achievement_name')
                ->label(self::text('fields.achievement'))
                ->formatStateUsing(static fn (AchievementGrantLogModel $record): string => GrantDisplay::achievementName($record->achievement, $record->achievement_name))
                ->wrap();
        }

        if ($withAccount) {
            $columns[] = TextColumn::make('account')
                ->label(self::text('fields.account'))
                ->state(static fn (AchievementGrantLogModel $record): string => GrantDisplay::accountName($record->user))
                ->wrap();
        }

        $columns[] = TextColumn::make('season_key')
            ->label(self::text('fields.season'))
            ->formatStateUsing(static fn (?string $state): ?string => GrantDisplay::seasonLabel($state))
            ->placeholder('—')
            ->visibleFrom('xl');

        $columns[] = TextColumn::make('source')
            ->label(self::text('fields.source'))
            ->formatStateUsing(static fn (string $state): string => GrantDisplay::sourceLabel($state))
            ->color(static fn (string $state): string => GrantDisplay::sourceColour($state))
            ->badge()
            ->visibleFrom('md');

        $columns[] = TextColumn::make('actor')
            ->label(self::text('fields.actor'))
            ->state(static fn (AchievementGrantLogModel $record): string => GrantDisplay::actorName($record->actor, $record->source))
            ->wrap()
            ->visibleFrom('xl');

        $columns[] = TextColumn::make('reason')
            ->label(self::text('fields.reason'))
            ->placeholder('—')
            ->wrap()
            ->visibleFrom('xl');

        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['achievement', 'user', 'actor']))
            ->columns($columns)
            ->recordActions([RowDetailsAction::makeIconButton()])
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->emptyStateHeading(self::text('log.empty'))
            ->paginated([10, 25, 50]);
    }

    private static function text(string $key): string
    {
        return (string) __('achievements::messages.grants.'.$key);
    }
}
