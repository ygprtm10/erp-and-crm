<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources;

use App\Concerns\RequiresModule;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Hris\Filament\Resources\PositionResource\Pages\CreatePosition;
use Modules\Hris\Filament\Resources\PositionResource\Pages\EditPosition;
use Modules\Hris\Filament\Resources\PositionResource\Pages\ListPositions;
use Modules\Hris\Models\Position;
use UnitEnum;

/**
 * Tenant-scoped Filament resource for managing job positions and salary ranges.
 */
class PositionResource extends Resource
{
    use RequiresModule;

    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = Position::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    /**
     * The navigation label displayed in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Jabatan';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'HRIS & Payroll';

    /**
     * Sort order inside the HRIS & Payroll navigation group.
     */
    protected static ?int $navigationSort = 2;

    /**
     * Return the required module slug controlling access to this resource.
     */
    public static function requiredModuleSlug(): string
    {
        return 'hris';
    }

    /**
     * Define the job position create and edit form schema.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detail Jabatan')
                    ->columns(2)
                    ->schema([
                        Select::make('department_id')
                            ->label('Departemen')
                            ->relationship('department', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        TextInput::make('code')
                            ->label('Kode Jabatan')
                            ->required()
                            ->scopedUnique(ignoreRecord: true)
                            ->maxLength(32),
                        TextInput::make('name')
                            ->label('Nama Jabatan')
                            ->required()
                            ->maxLength(120)
                            ->columnSpanFull(),
                        TextInput::make('base_salary_min')
                            ->label('Rentang Gaji Minimum')
                            ->numeric()
                            ->minValue(0)
                            ->default('0.0000')
                            ->prefix('Rp'),
                        TextInput::make('base_salary_max')
                            ->label('Rentang Gaji Maksimum')
                            ->numeric()
                            ->minValue(0)
                            ->default('0.0000')
                            ->prefix('Rp'),
                    ]),
            ]);
    }

    /**
     * Define the job position listing table schema.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama Jabatan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('department.name')
                    ->label('Departemen')
                    ->placeholder('-'),
                TextColumn::make('base_salary_min')
                    ->label('Gaji Min')
                    ->money('IDR')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('base_salary_max')
                    ->label('Gaji Max')
                    ->money('IDR')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
            ])
            ->filters([
                SelectFilter::make('department_id')
                    ->label('Departemen')
                    ->relationship('department', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * Register the pages belonging to this resource.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPositions::route('/'),
            'create' => CreatePosition::route('/create'),
            'edit' => EditPosition::route('/{record}/edit'),
        ];
    }
}
