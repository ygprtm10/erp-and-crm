<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Admin\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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
use Modules\Platform\Filament\Admin\Resources\SaasBundleResource\Pages\CreateSaasBundle;
use Modules\Platform\Filament\Admin\Resources\SaasBundleResource\Pages\EditSaasBundle;
use Modules\Platform\Filament\Admin\Resources\SaasBundleResource\Pages\ListSaasBundles;
use Modules\Platform\Models\SaasBundle;
use Modules\Platform\Models\SaasModule;
use UnitEnum;

/**
 * Super Admin resource for managing curated SaaS industry bundles.
 */
class SaasBundleResource extends Resource
{
    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = SaasBundle::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    /**
     * The navigation group in the Super Admin sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'SaaS Catalog';

    /**
     * Define the create/edit form schema for SaasBundle records.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Bundle Details')
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
                        TextInput::make('annual_price')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default('0.0000')
                            ->prefix('IDR'),
                        Select::make('module_ids')
                            ->label('Included Modules')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => SaasModule::query()->pluck('name', 'id')->all()),
                        Toggle::make('is_active')
                            ->default(true)
                            ->required(),
                    ]),
            ]);
    }

    /**
     * Define the listing table schema for SaasBundle records.
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
                TextColumn::make('annual_price')
                    ->money('IDR')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active Status'),
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
     * Register pages belonging to the SaasBundle resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSaasBundles::route('/'),
            'create' => CreateSaasBundle::route('/create'),
            'edit' => EditSaasBundle::route('/{record}/edit'),
        ];
    }
}
