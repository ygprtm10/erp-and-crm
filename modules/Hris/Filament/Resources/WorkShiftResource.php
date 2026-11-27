<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources;

use App\Concerns\RequiresModule;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Hris\Filament\Resources\WorkShiftResource\Pages\CreateWorkShift;
use Modules\Hris\Filament\Resources\WorkShiftResource\Pages\EditWorkShift;
use Modules\Hris\Filament\Resources\WorkShiftResource\Pages\ListWorkShifts;
use Modules\Hris\Models\WorkShift;
use UnitEnum;

/**
 * Tenant-scoped Filament resource for managing employee work shift schedules.
 */
class WorkShiftResource extends Resource
{
    use RequiresModule;

    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = WorkShift::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    /**
     * The navigation label displayed in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Shift Kerja';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'HRIS & Payroll';

    /**
     * Sort order inside the HRIS & Payroll navigation group.
     */
    protected static ?int $navigationSort = 3;

    /**
     * Return the required module slug controlling access to this resource.
     */
    public static function requiredModuleSlug(): string
    {
        return 'hris';
    }

    /**
     * Define the work shift create and edit form schema.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Jadwal Shift Kerja')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama Shift')
                            ->required()
                            ->maxLength(120)
                            ->columnSpanFull(),
                        TimePicker::make('clock_in_time')
                            ->label('Jam Masuk')
                            ->required(),
                        TimePicker::make('clock_out_time')
                            ->label('Jam Pulang')
                            ->required(),
                        TextInput::make('late_tolerance_minutes')
                            ->label('Toleransi Terlambat (Menit)')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(240)
                            ->default(15),
                        Toggle::make('is_overnight')
                            ->label('Shift Lintas Malam')
                            ->default(false),
                    ]),
            ]);
    }

    /**
     * Define the work shift listing table schema.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Shift')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('clock_in_time')
                    ->label('Jam Masuk')
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('clock_out_time')
                    ->label('Jam Pulang')
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('late_tolerance_minutes')
                    ->label('Toleransi (Menit)')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                IconColumn::make('is_overnight')
                    ->label('Lintas Malam')
                    ->boolean(),
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
            'index' => ListWorkShifts::route('/'),
            'create' => CreateWorkShift::route('/create'),
            'edit' => EditWorkShift::route('/{record}/edit'),
        ];
    }
}
