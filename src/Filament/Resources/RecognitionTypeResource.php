<?php

declare(strict_types=1);

namespace Modules\Achievements\Filament\Resources;

use App\Filament\Actions\RowDetailsAction;
use App\Filament\Resources\BaseResource;
use App\Filament\Support\PanelNavigationGroup;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Achievements\Application\Services\AchievementsSettingsInterface;
use Modules\Achievements\Domain\ValueObjects\RecognitionTypeName;
use Modules\Achievements\Filament\Resources\RecognitionTypeResource\Pages\CreateRecognitionType;
use Modules\Achievements\Filament\Resources\RecognitionTypeResource\Pages\EditRecognitionType;
use Modules\Achievements\Filament\Resources\RecognitionTypeResource\Pages\ListRecognitionTypes;
use Modules\Achievements\Infrastructure\Persistence\Eloquent\Models\RecognitionTypeModel;

/**
 * «Personas → Logros → Tipos de reconocimiento» (#11): the kinds of recognition people can give
 * each other. Listed with `achievements.view_any`, written with `achievements.manage`
 * (RecognitionTypePolicy). Only while the «Reconocimientos» switch is on.
 */
final class RecognitionTypeResource extends BaseResource
{
    protected static ?string $model = RecognitionTypeModel::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-hand-thumb-up';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'tipos-de-reconocimiento';

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
        return parent::canAccess() && app(AchievementsSettingsInterface::class)->recognitionsEnabled();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(self::text('sections.type'))
                ->description(self::text('sections.type_description'))
                ->schema([
                    TextInput::make('name')
                        ->label(self::text('fields.name'))
                        ->required()
                        ->maxLength(RecognitionTypeName::MAX_LENGTH)
                        ->dehydrateStateUsing(static fn (mixed $state): string => RecognitionTypeName::normalise(is_string($state) ? $state : '')),

                    Select::make('icon')
                        ->label(self::text('fields.icon'))
                        ->options(AchievementResource::iconOptions())
                        ->searchable()
                        ->native(false)
                        ->required(),

                    Select::make('colour')
                        ->label(self::text('fields.colour'))
                        ->options(AchievementResource::colourOptions())
                        ->native(false)
                        ->required(),

                    Toggle::make('is_active')
                        ->label(self::text('fields.is_active'))
                        ->helperText(self::text('helpers.is_active'))
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(self::text('fields.name'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                IconColumn::make('is_active')
                    ->label(self::text('fields.is_active'))
                    ->boolean(),

                TextColumn::make('icon')
                    ->label(self::text('fields.icon'))
                    ->formatStateUsing(static fn (string $state): string => AchievementResource::iconOptions()[$state] ?? $state)
                    ->visibleFrom('sm'),

                TextColumn::make('colour')
                    ->label(self::text('fields.colour'))
                    ->formatStateUsing(static fn (string $state): string => AchievementResource::colourOptions()[$state] ?? $state)
                    ->visibleFrom('sm'),
            ])
            ->recordActions([
                ActionGroup::make([
                    RowDetailsAction::make(),
                    EditAction::make(),
                    self::deleteAction(DeleteAction::make()),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function deleteAction(DeleteAction $action): DeleteAction
    {
        return $action
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
            'index' => ListRecognitionTypes::route('/'),
            'create' => CreateRecognitionType::route('/create'),
            'edit' => EditRecognitionType::route('/{record}/edit'),
        ];
    }

    private static function text(string $key): string
    {
        return (string) __('achievements::messages.recognition_types.'.$key);
    }
}
