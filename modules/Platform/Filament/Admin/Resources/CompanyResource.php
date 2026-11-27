<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Admin\Resources;

use App\Models\Company;
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
use Modules\Platform\Filament\Admin\Resources\CompanyResource\Pages\CreateCompany;
use Modules\Platform\Filament\Admin\Resources\CompanyResource\Pages\EditCompany;
use Modules\Platform\Filament\Admin\Resources\CompanyResource\Pages\ListCompanies;
use UnitEnum;

/**
 * Super Admin resource for managing multi-tenant Company records.
 */
class CompanyResource extends Resource
{
    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = Company::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    /**
     * The navigation group in the Super Admin sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Tenant Management';

    /**
     * Define the create/edit form schema for Company records.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Company Identity')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->required()
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        TextInput::make('custom_domain')
                            ->nullable()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Select::make('business_type')
                            ->required()
                            ->default('general')
                            ->options([
                                'general' => 'General Business',
                                'retail' => 'Retail / Store',
                                'fnb' => 'Food & Beverage',
                                'laundry' => 'Laundry Service',
                                'service' => 'Service / Repair',
                                'gym' => 'Gym & Studio',
                            ]),
                        Toggle::make('is_active')
                            ->default(true)
                            ->required(),
                    ]),
            ]);
    }

    /**
     * Define the listing table schema for Company records.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('business_type')
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
     * Register pages belonging to the Company resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
