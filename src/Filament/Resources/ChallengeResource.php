<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources;

use App\Application\Services\TimezoneServiceInterface;
use App\Filament\Actions\RowDetailsAction;
use App\Filament\Resources\BaseResource;
use App\Filament\Support\PanelNavigationGroup;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\Challenges\ChallengeRule;
use Modules\Achievements\Domain\Challenges\ChallengeState;
use Modules\Achievements\Domain\Repositories\ChallengeRepositoryInterface;
use Modules\Achievements\Filament\Resources\ChallengeResource\Pages\CreateChallenge;
use Modules\Achievements\Filament\Resources\ChallengeResource\Pages\EditChallenge;
use Modules\Achievements\Filament\Resources\ChallengeResource\Pages\ListChallenges;
use Modules\Achievements\Filament\Support\AchievementRuleForm;
use Modules\Achievements\Filament\Support\ChallengeForm;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\ChallengeModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Repositories\EloquentChallengeRepository;

/**
 * «Personas → Logros → Retos»: collective challenges (#12). Listed with `achievements.view_any`,
 * created, edited, closed and deleted with `achievements.manage` (ChallengePolicy). Only while the
 * «Retos» switch is on: off, the entry and its pages are gone (what was granted stays).
 */
final class ChallengeResource extends BaseResource
{
    protected static ?string $model = ChallengeModel::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'retos';

    public const string CLOSE_ACTION = 'closeChallenge';

    public static function getNavigationGroup(): string
    {
        return PanelNavigationGroup::People->getLabel();
    }

    /**
     * Under «Logros», in the same group
     */
    public static function getNavigationParentItem(): string
    {
        return AchievementResource::getNavigationLabel();
    }

    public static function getNavigationLabel(): string
    {
        return self::text('navigation');
    }

    public static function getModelLabel(): string
    {
        return self::text('model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return self::text('model.plural');
    }

    public static function canAccess(): bool
    {
        return parent::canAccess() && app(AchievementsSettingsInterface::class)->challengesEnabled();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(ChallengeForm::sections());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('reward'))
            ->columns([
                TextColumn::make('name')
                    ->label(self::text('fields.name'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('state')
                    ->label(self::text('fields.state'))
                    ->state(static fn (ChallengeModel $record): string => self::state($record)->value)
                    ->formatStateUsing(static fn (string $state): string => self::text('states.'.$state))
                    ->color(static fn (string $state): string => self::stateColour(ChallengeState::from($state)))
                    ->badge(),

                TextColumn::make('progress')
                    ->label(self::text('fields.progress'))
                    ->state(static fn (ChallengeModel $record): string => self::progressSummary($record))
                    ->alignCenter()
                    ->visibleFrom('sm'),

                TextColumn::make('days')
                    ->label(self::text('fields.days'))
                    ->state(static fn (ChallengeModel $record): string => self::text('days', [
                        'start' => $record->starts_on->format('d/m/Y'),
                        'end' => $record->ends_on->format('d/m/Y'),
                    ]))
                    ->visibleFrom('md'),

                TextColumn::make('what')
                    ->label(self::text('fields.what'))
                    ->state(static fn (ChallengeModel $record): string => self::ruleSummary($record))
                    ->wrap()
                    ->visibleFrom('xl'),

                TextColumn::make('reward.name')
                    ->label(self::text('fields.reward'))
                    ->wrap()
                    ->visibleFrom('xl'),

                TextColumn::make('contributors')
                    ->label(self::text('fields.contributors'))
                    ->alignCenter()
                    ->visibleFrom('2xl'),
            ])
            ->filters([
                SelectFilter::make('open')
                    ->label(self::text('filters.open'))
                    ->options([
                        'open' => self::text('filters.options.open'),
                        'closed' => self::text('filters.options.closed'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'open' => $query->whereNull('closed_at'),
                        'closed' => $query->whereNotNull('closed_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    RowDetailsAction::make(),
                    EditAction::make(),
                    self::closeAction(Action::make(self::CLOSE_ACTION)),
                    self::deleteAction(DeleteAction::make()),
                ]),
            ])
            ->defaultSort('starts_on', 'desc');
    }

    /**
     * Closing is for good: nothing more counts and nobody else gets the reward. A dialog and a box
     * to tick; the policy decides again when it runs.
     */
    public static function closeAction(Action $action): Action
    {
        return $action
            ->label(self::text('close.label'))
            ->icon('heroicon-o-lock-closed')
            ->color('warning')
            ->visible(static fn (ChallengeModel $record): bool => auth()->user()?->can('close', $record) === true)
            ->authorize(static fn (ChallengeModel $record): bool => auth()->user()?->can('close', $record) === true)
            ->requiresConfirmation()
            ->modalHeading(self::text('close.heading'))
            ->modalDescription(self::text('close.description'))
            ->schema([
                Checkbox::make('confirmation')
                    ->label(self::text('close.confirmation'))
                    ->accepted()
                    ->required(),
            ])
            ->action(static function (ChallengeModel $record): void {
                $actor = auth()->user()?->getAuthIdentifier();
                $closed = app(ChallengeRepositoryInterface::class)->close($record->id, is_string($actor) ? $actor : null, Date::now()->toDateTimeImmutable());

                $notification = Notification::make()->title(self::text($closed ? 'close.done' : 'close.already'));
                $closed ? $notification->success() : $notification->warning();
                $notification->send();
            });
    }

    /**
     * Only a challenge that never completed (ChallengePolicy::delete()).
     */
    public static function deleteAction(DeleteAction $action): DeleteAction
    {
        return $action
            // Decided again under the row lock: a count may have completed it since the dialog opened
            ->using(static fn (ChallengeModel $record): bool => app(ChallengeRepositoryInterface::class)->deleteUnlessCompleted($record->id))
            ->failureNotificationTitle(self::text('delete.completed_meanwhile'))
            ->modalDescription(self::text('delete.description'))
            ->schema([
                Checkbox::make('confirmation')
                    ->label(self::text('delete.confirmation'))
                    ->accepted()
                    ->required(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChallenges::route('/'),
            'create' => CreateChallenge::route('/create'),
            'edit' => EditChallenge::route('/{record}/edit'),
        ];
    }

    public static function state(ChallengeModel $record): ChallengeState
    {
        return EloquentChallengeRepository::toEntity($record)->state(app(TimezoneServiceInterface::class)->today()->format('Y-m-d'));
    }

    public static function stateColour(ChallengeState $state): string
    {
        return match ($state) {
            ChallengeState::Running => 'info',
            ChallengeState::Completed => 'success',
            ChallengeState::Upcoming => 'gray',
            ChallengeState::Ended, ChallengeState::Closed => 'warning',
        };
    }

    public static function progressSummary(ChallengeModel $record): string
    {
        $rule = ChallengeRule::tryFrom($record->getRawOriginal('rule'));

        return $rule instanceof ChallengeRule
            ? self::text('progress', ['progress' => $record->progress, 'target' => $rule->target])
            : '—';
    }

    /**
     * What it counts, for the panel («Partidas · en total · como jugador»).
     */
    public static function ruleSummary(ChallengeModel $record): string
    {
        $rule = ChallengeRule::tryFrom($record->getRawOriginal('rule'));

        if (! $rule instanceof ChallengeRule) {
            return self::text('invalid_rule');
        }

        $parts = [AchievementRuleForm::kindLabel($rule->kind), self::text('metrics.'.$rule->metric->value)];

        if ($rule->role !== null) {
            $parts[] = __('achievements::messages.catalogue.rule.roles.'.$rule->role);
        }

        if ($rule->attributeKey !== null) {
            $parts[] = $rule->attributeLabel ?? $rule->attributeValue ?? '';
        }

        if ($rule->placementMax !== null) {
            $parts[] = self::text('placement_summary', ['max' => $rule->placementMax]);
        }

        return implode(' · ', array_filter(array_map(strval(...), $parts), static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        return (string) __('achievements::messages.challenges.'.$key, $replace);
    }
}
