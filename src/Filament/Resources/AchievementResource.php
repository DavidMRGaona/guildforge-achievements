<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources;

use App\Application\Profiles\ProfileBadgeIcons;
use App\Domain\Profiles\ProfileBadgeColour;
use App\Filament\Actions\RowDetailsAction;
use App\Filament\Forms\Components\MediaLibraryPicker;
use App\Filament\Resources\BaseResource;
use App\Filament\Support\PanelNavigationGroup;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Modules\Achievements\Application\Enums\AchievementStatus;
use Modules\Achievements\Application\Services\AchievementRarityInterface;
use Modules\Achievements\Filament\Media\AchievementBadgeImageMediaSource;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\CreateAchievement;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\EditAchievement;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\ListAchievements;
use Modules\Achievements\Filament\Resources\AchievementResource\Pages\ViewAchievement;
use Modules\Achievements\Filament\Resources\AchievementResource\RelationManagers\GrantLogRelationManager;
use Modules\Achievements\Filament\Resources\AchievementResource\RelationManagers\GrantsRelationManager;
use Modules\Achievements\Filament\Support\AchievementPanelView;
use Modules\Achievements\Filament\Support\AchievementRuleForm;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementGrantModel;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\AchievementModel;
use Modules\Achievements\Policies\AchievementPolicy;

/**
 * «Personas → Logros»: the catalogue. Listed with `achievements.view_any`, written with
 * `achievements.manage` (AchievementPolicy). Whoever cannot manage it sees each secret achievement
 * as «Logro secreto», without its rule, and searching never matches one.
 */
final class AchievementResource extends BaseResource
{
    protected static ?string $model = AchievementModel::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-trophy';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'logros';

    public static function getNavigationGroup(): string
    {
        return PanelNavigationGroup::People->getLabel();
    }

    public static function getNavigationLabel(): string
    {
        return __('achievements::messages.catalogue.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('achievements::messages.catalogue.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('achievements::messages.catalogue.model.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(self::text('sections.badge'))
                    ->description(self::text('sections.badge_description'))
                    ->schema([
                        TextInput::make('name')
                            ->label(self::text('fields.name'))
                            ->required()
                            ->maxLength(60),

                        Textarea::make('description')
                            ->label(self::text('fields.description'))
                            ->helperText(self::text('helpers.description'))
                            ->maxLength(200)
                            ->rows(2)
                            ->dehydrateStateUsing(static fn (mixed $state): string => is_string($state) ? trim($state) : ''),

                        Select::make('icon')
                            ->label(self::text('fields.icon'))
                            ->options(self::iconOptions())
                            ->searchable()
                            ->native(false)
                            ->required(),

                        Select::make('colour')
                            ->label(self::text('fields.colour'))
                            ->options(self::colourOptions())
                            ->native(false)
                            ->required(),

                        MediaLibraryPicker::attachTo(FileUpload::make('image_public_id')
                            ->label(self::text('fields.image'))
                            ->helperText(self::text('helpers.image'))
                            ->image()
                            ->disk('images')
                            ->directory(fn (): string => AchievementBadgeImageMediaSource::FOLDER.now()->format('Y/m'))
                            ->getUploadedFileNameForStorageUsing(
                                fn (TemporaryUploadedFile $file): string => Str::uuid()->toString().'.'.$file->getClientOriginalExtension()
                            )
                            ->maxSize(1024)
                            ->nullable()
                            ->columnSpanFull()),

                        TextInput::make('points')
                            ->label(self::text('fields.points'))
                            ->helperText(self::text('helpers.points'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10_000)
                            ->default(0)
                            ->required(),

                        Toggle::make('is_secret')
                            ->label(self::text('fields.is_secret'))
                            ->helperText(self::text('helpers.is_secret')),

                        Toggle::make('is_active')
                            ->label(self::text('fields.is_active'))
                            ->helperText(self::text('helpers.is_active'))
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make(self::text('sections.rule'))
                    ->description(self::text('sections.rule_description'))
                    ->schema(AchievementRuleForm::components())
                    ->columns(2),
            ]);
    }

    /**
     * People holding each achievement: now for whoever may grant or manage (they see the holders
     * anyway), as of the last nightly photo for anybody else, so a count never rises at the moment
     * two people play together. Without a photo, nothing yet.
     *
     * @return Builder<AchievementGrantModel>
     */
    private static function holdersCount(): Builder
    {
        $query = AchievementGrantModel::query()
            ->selectRaw('count(distinct user_id)')
            ->whereColumn('achievement_id', 'achievements_catalogue.id');
        $viewer = Auth::user();

        if ($viewer instanceof UserModel && AchievementPolicy::seesGrants($viewer)) {
            return $query;
        }

        $takenAt = app(AchievementRarityInterface::class)->current()->takenAt;

        return $takenAt instanceof DateTimeImmutable
            ? $query->where('granted_at', '<', $takenAt->format('Y-m-d H:i:s'))
            : $query->whereRaw('1 = 0');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->addSelect([
                'holders_count' => self::holdersCount(),
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label(self::text('fields.name'))
                    ->formatStateUsing(static fn (AchievementModel $record): string => self::shownName($record))
                    ->searchable(query: static fn (Builder $query, string $search): Builder => self::search($query, $search))
                    ->sortable(query: static fn (Builder $query, string $direction): Builder => self::sortByShownName($query, $direction))
                    ->wrap(),

                TextColumn::make('status')
                    ->label(self::text('fields.status'))
                    ->state(static fn (AchievementModel $record): string => AchievementPanelView::status($record)->value)
                    ->formatStateUsing(static fn (string $state): string => AchievementStatus::from($state)->label())
                    ->color(static fn (string $state): string => AchievementStatus::from($state)->colour())
                    ->badge(),

                // People, not grants: one that repeats per season has a row per season
                TextColumn::make('holders_count')
                    ->label(self::text('fields.holders'))
                    ->sortable(query: static fn (Builder $query, string $direction): Builder => $query->orderBy('holders_count', $direction === 'desc' ? 'desc' : 'asc'))
                    ->alignCenter()
                    ->visibleFrom('sm'),

                TextColumn::make('rule_summary')
                    ->label(self::text('fields.rule'))
                    ->state(static fn (AchievementModel $record): string => self::masks($record) ? '—' : AchievementPanelView::ruleSummary($record))
                    ->wrap()
                    ->visibleFrom('md'),

                TextColumn::make('points')
                    ->label(self::text('fields.points'))
                    ->sortable()
                    ->alignCenter()
                    ->visibleFrom('xl'),

                IconColumn::make('is_secret')
                    ->label(self::text('fields.is_secret'))
                    ->boolean()
                    ->visibleFrom('xl'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(self::text('fields.is_active')),
                TernaryFilter::make('is_secret')
                    ->label(self::text('fields.is_secret')),
            ])
            ->recordActions([
                ActionGroup::make([
                    RowDetailsAction::make(),
                    EditAction::make(),
                    self::guardDeletion(DeleteAction::make())
                        // Only offered while nobody holds it (AchievementPolicy::delete())
                        ->modalDescription(self::text('delete.description'))
                        ->schema([
                            Checkbox::make('confirmation')
                                ->label(self::text('delete.confirmation'))
                                ->accepted()
                                ->required(),
                        ]),
                ]),
            ])
            ->defaultSort(static fn (Builder $query): Builder => self::sortByShownName($query, 'asc'));
    }

    /**
     * What the view page shows (AchievementPolicy::view() keeps secret ones for whoever manages the
     * catalogue, so nothing here is masked).
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(self::text('sections.badge'))
                    ->schema([
                        TextEntry::make('name')
                            ->label(self::text('fields.name')),
                        TextEntry::make('status')
                            ->label(self::text('fields.status'))
                            ->state(static fn (AchievementModel $record): string => AchievementPanelView::status($record)->value)
                            ->formatStateUsing(static fn (string $state): string => AchievementStatus::from($state)->label())
                            ->color(static fn (string $state): string => AchievementStatus::from($state)->colour())
                            ->badge(),
                        TextEntry::make('description')
                            ->label(self::text('fields.description'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('rule_summary')
                            ->label(self::text('fields.rule'))
                            ->state(static fn (AchievementModel $record): string => AchievementPanelView::ruleSummary($record)),
                        TextEntry::make('points')
                            ->label(self::text('fields.points')),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            GrantsRelationManager::class,
            GrantLogRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAchievements::route('/'),
            'create' => CreateAchievement::route('/create'),
            'view' => ViewAchievement::route('/{record}'),
            'edit' => EditAchievement::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function iconOptions(): array
    {
        $options = [];

        foreach (ProfileBadgeIcons::NAMES as $icon) {
            $key = 'achievements::messages.catalogue.icons.'.$icon;
            $label = __($key);
            $options[$icon] = is_string($label) && $label !== $key ? $label : $icon;
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function colourOptions(): array
    {
        $options = [];

        foreach (ProfileBadgeColour::cases() as $colour) {
            $options[$colour->value] = self::text('colours.'.$colour->value);
        }

        return $options;
    }

    /**
     * Somebody may get the achievement between opening the dialog and confirming: the database then
     * refuses the deletion (restricting foreign key) and a notice says to switch it off instead.
     * Only that refusal (SQLSTATE 23503 in PostgreSQL, 23000 naming the foreign key in SQLite): any
     * other database error is thrown as it came.
     */
    public static function guardDeletion(DeleteAction $action): DeleteAction
    {
        return $action
            ->using(static function (AchievementModel $record): bool {
                try {
                    return (bool) DB::transaction(static fn (): ?bool => $record->delete());
                } catch (QueryException $e) {
                    if (self::isForeignKeyRefusal($e)) {
                        return false;
                    }

                    throw $e;
                }
            })
            ->failureNotificationTitle(self::text('delete.held_meanwhile'));
    }

    private static function isForeignKeyRefusal(QueryException $e): bool
    {
        $state = $e->errorInfo[0] ?? (string) $e->getCode();

        return $state === '23503'
            || ($state === '23000' && str_contains(strtoupper($e->getMessage()), 'FOREIGN KEY'));
    }

    /**
     * Whether the current person sees this achievement masked: a secret one, for whoever cannot
     * manage the catalogue.
     */
    public static function masks(AchievementModel $record): bool
    {
        return $record->is_secret && ! self::canCreate();
    }

    public static function shownName(AchievementModel $record): string
    {
        return self::masks($record) ? (string) __('achievements::messages.secret.name') : $record->name;
    }

    /**
     * @param  Builder<AchievementModel>  $query
     * @return Builder<AchievementModel>
     */
    private static function search(Builder $query, string $search): Builder
    {
        return $query->where(static function (Builder $inner) use ($search): void {
            $pattern = '%'.addcslashes(mb_strtolower($search), '!%_').'%';
            $inner->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);

            // A secret name never answers a search of someone who only sees «Logro secreto»
            if (! self::canCreate()) {
                $inner->where('is_secret', false);
            }
        });
    }

    /**
     * By the name each person sees: for whoever sees «Logro secreto», every secret achievement sorts
     * under that name (then by id), so where a secret name would fall never shows.
     *
     * @param  Builder<AchievementModel>  $query
     * @return Builder<AchievementModel>
     */
    private static function sortByShownName(Builder $query, string $direction): Builder
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        if (self::canCreate()) {
            return $query->orderBy('name', $direction)->orderBy('id', $direction);
        }

        return $query
            ->orderByRaw('CASE WHEN is_secret THEN ? ELSE name END '.$direction, [(string) __('achievements::messages.secret.name')])
            ->orderBy('id', $direction);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        return (string) __('achievements::messages.catalogue.'.$key, $replace);
    }
}
