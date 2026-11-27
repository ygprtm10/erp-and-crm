<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Admin\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Platform\Filament\Admin\Resources\SaasModuleResource\Pages\CreateSaasModule;
use Modules\Platform\Filament\Admin\Resources\SaasModuleResource\Pages\EditSaasModule;
use Modules\Platform\Filament\Admin\Resources\SaasModuleResource\Pages\ListSaasModules;
use Modules\Platform\Models\SaasModule;
use UnitEnum;

/**
 * Super Admin resource for managing global SaaS module definitions and add-on pricing.
 */
class SaasModuleResource extends Resource
{
    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = SaasModule::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    /**
     * The navigation group in the Super Admin sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'SaaS Catalog';

    /**
     * Define the create/edit form schema for SaasModule records.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Module Configuration')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->required()
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->maxLength(100),
                        TextInput::make('monthly_price')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default('0.0000')
                            ->prefix('IDR'),
                        Select::make('required_modules')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn (?SaasModule $record): array => SaasModule::query()
                                ->when($record !== null, fn ($query) => $query->whereKeyNot($record->getKey()))
                                ->pluck('name', 'slug')
                                ->all()),
                    ]),
            ]);
    }

    /**
     * Define the listing table schema for SaasModule records.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->badge()
                    ->searchable(),
                TextColumn::make('monthly_price')
                    ->money('IDR')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
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

    /**
     * Register pages belonging to the SaasModule resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSaasModules::route('/'),
            'create' => CreateSaasModule::route('/create'),
            'edit' => EditSaasModule::route('/{record}/edit'),
        ];
    }
}
