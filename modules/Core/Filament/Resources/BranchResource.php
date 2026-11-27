<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Core\Filament\Resources\BranchResource\Pages\CreateBranch;
use Modules\Core\Filament\Resources\BranchResource\Pages\EditBranch;
use Modules\Core\Filament\Resources\BranchResource\Pages\ListBranches;
use Modules\Core\Models\Branch;
use UnitEnum;

/**
 * Tenant-scoped resource for managing operational company branches.
 */
class BranchResource extends Resource
{
    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = Branch::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    /**
     * Define the create/edit form schema for Branch records.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Branch Identity')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->scopedUnique(ignoreRecord: true)
                            ->maxLength(32),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->tel()
                            ->nullable()
                            ->maxLength(30),
                        Toggle::make('is_main')
                            ->label('Main Branch')
                            ->default(false),
                        Textarea::make('address')
                            ->nullable()
                            ->rows(2)
                            ->columnSpanFull(),
                        TextInput::make('latitude')
                            ->numeric()
                            ->nullable(),
                        TextInput::make('longitude')
                            ->numeric()
                            ->nullable(),
                    ]),
            ]);
    }

    /**
     * Define the listing table schema for Branch records.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->searchable(),
                IconColumn::make('is_main')
                    ->label('Main')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_main')
                    ->label('Main Branch'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Register pages belonging to the Branch resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }
}
