<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\TopModelResource\Pages;
use App\Models\TopModel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TopModelResource extends Resource
{
    protected static ?string $model = TopModel::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::UserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Management';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('season_id')
                    ->relationship('season', 'name')
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->maxLength(255)
                    ->disabled(fn (string $operation): bool => $operation === 'edit'),
                FileUpload::make('image')
                    ->image()
                    ->directory('top-models'),
                Toggle::make('is_eliminated'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                ImageColumn::make('image'),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('season.name')
                    ->sortable()
                    ->searchable(),
                IconColumn::make('is_eliminated')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTopModels::route('/'),
            'create' => Pages\CreateTopModel::route('/create'),
            'edit' => Pages\EditTopModel::route('/{record}/edit'),
        ];
    }
}
