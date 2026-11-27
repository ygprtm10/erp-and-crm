<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources;

use App\Concerns\RequiresModule;
use App\Models\Company;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Enums\PtkpStatus;
use Modules\Hris\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
use Modules\Hris\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use Modules\Hris\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use Modules\Hris\Models\Employee;
use UnitEnum;

/**
 * Tenant-scoped Filament resource for managing employees, compensation, tax/BPJS toggles, and RFID credentials.
 */
class EmployeeResource extends Resource
{
    use RequiresModule;

    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = Employee::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    /**
     * The navigation label displayed in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Karyawan';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'HRIS & Payroll';

    /**
     * Sort order inside the HRIS & Payroll navigation group.
     */
    protected static ?int $navigationSort = 4;

    /**
     * Return the required module slug controlling access to this resource.
     */
    public static function requiredModuleSlug(): string
    {
        return 'hris';
    }

    /**
     * Define the tabbed employee master form schema.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('EmployeeTabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Data Pribadi & Kerja')
                            ->icon(Heroicon::OutlinedIdentification)
                            ->columns(2)
                            ->schema([
                                TextInput::make('employee_code')
                                    ->label('NIP / Kode Karyawan')
                                    ->required()
                                    ->scopedUnique(ignoreRecord: true)
                                    ->maxLength(32),
                                TextInput::make('full_name')
                                    ->label('Nama Lengkap')
                                    ->required()
                                    ->maxLength(150),
                                TextInput::make('email')
                                    ->label('Email')
                                    ->email()
                                    ->maxLength(150),
                                TextInput::make('phone')
                                    ->label('Nomor Telepon')
                                    ->maxLength(32),
                                Select::make('branch_id')
                                    ->label('Cabang')
                                    ->relationship('branch', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->nullable(),
                                Select::make('department_id')
                                    ->label('Departemen')
                                    ->relationship('department', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->nullable(),
                                Select::make('position_id')
                                    ->label('Jabatan')
                                    ->relationship('position', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->nullable(),
                                Select::make('work_shift_id')
                                    ->label('Shift Kerja Default')
                                    ->relationship('workShift', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->nullable(),
                                Select::make('employment_status')
                                    ->label('Status Kerja')
                                    ->required()
                                    ->default(EmploymentStatus::Active->value)
                                    ->options(collect(EmploymentStatus::cases())->mapWithKeys(
                                        static fn (EmploymentStatus $status): array => [$status->value => $status->label()],
                                    )->all()),
                                DatePicker::make('join_date')
                                    ->label('Tanggal Bergabung')
                                    ->required()
                                    ->default(now()),
                                Select::make('user_id')
                                    ->label('Akun User Terkait (Opsional)')
                                    ->searchable()
                                    ->nullable()
                                    ->options(static function (): array {
                                        $tenant = Filament::getTenant();

                                        if (! $tenant instanceof Company) {
                                            return [];
                                        }

                                        return $tenant->users()
                                            ->orderBy('name')
                                            ->pluck('users.name', 'users.id')
                                            ->all();
                                    }),
                            ]),
                        Tab::make('Gaji & Rekening Bank')
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->columns(2)
                            ->schema([
                                TextInput::make('base_salary')
                                    ->label('Gaji Pokok')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->prefix('Rp'),
                                TextInput::make('fixed_allowance')
                                    ->label('Tunjangan Tetap')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->default('0.0000')
                                    ->prefix('Rp'),
                                Select::make('ptkp_status')
                                    ->label('Status PTKP')
                                    ->required()
                                    ->default(PtkpStatus::Tk0->value)
                                    ->options(collect(PtkpStatus::cases())->mapWithKeys(
                                        static fn (PtkpStatus $ptkp): array => [$ptkp->value => "{$ptkp->value} (TER Kategori {$ptkp->terCategory()})"],
                                    )->all()),
                                TextInput::make('bank_name')
                                    ->label('Nama Bank')
                                    ->maxLength(64),
                                TextInput::make('bank_account_number')
                                    ->label('Nomor Rekening')
                                    ->maxLength(64),
                                TextInput::make('bank_account_holder')
                                    ->label('Nama Pemilik Rekening')
                                    ->maxLength(150),
                            ]),
                        Tab::make('Sakelar PPh 21 & BPJS')
                            ->icon(Heroicon::OutlinedShieldCheck)
                            ->columns(1)
                            ->schema([
                                Toggle::make('enable_pph21')
                                    ->label('Aktifkan Pemotongan PPh 21 (TER)')
                                    ->default(false)
                                    ->helperText('PPh 21 dihitung saat generate payroll jika sakelar periode dan karyawan sama-sama aktif.'),
                                Toggle::make('enable_bpjs_kesehatan')
                                    ->label('Aktifkan BPJS Kesehatan')
                                    ->default(false)
                                    ->helperText('Potongan karyawan 1% dan kontribusi perusahaan 4% (batas upah Rp 12.000.000).'),
                                Toggle::make('enable_bpjs_ketenagakerjaan')
                                    ->label('Aktifkan BPJS Ketenagakerjaan')
                                    ->default(false)
                                    ->helperText('Mencakup JHT, JP (dengan batas upah JP), JKK sesuai risiko perusahaan, dan JKM.'),
                            ]),
                        Tab::make('ID Kartu RFID')
                            ->icon(Heroicon::OutlinedCreditCard)
                            ->columns(1)
                            ->schema([
                                TextInput::make('rfid_uid')
                                    ->label('UID Kartu RFID')
                                    ->scopedUnique(ignoreRecord: true)
                                    ->maxLength(64)
                                    ->helperText('UID kartu RFID untuk integrasi mesin absensi IoT ESP32 pada Tahap 8.'),
                            ]),
                    ]),
            ]);
    }

    /**
     * Define the employee listing table schema and filters.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('employee_code')
            ->columns([
                TextColumn::make('employee_code')
                    ->label('NIP')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('full_name')
                    ->label('Nama Karyawan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('branch.name')
                    ->label('Cabang')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('department.name')
                    ->label('Departemen')
                    ->placeholder('-'),
                TextColumn::make('position.name')
                    ->label('Jabatan')
                    ->placeholder('-'),
                TextColumn::make('base_salary')
                    ->label('Gaji Pokok')
                    ->money('IDR')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums'])
                    ->sortable(),
                IconColumn::make('enable_pph21')
                    ->label('PPh 21')
                    ->boolean(),
                TextColumn::make('employment_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (EmploymentStatus $state): string => $state->label())
                    ->color(static fn (EmploymentStatus $state): string => match ($state) {
                        EmploymentStatus::Active => 'success',
                        EmploymentStatus::Probation => 'warning',
                        EmploymentStatus::Resigned, EmploymentStatus::Terminated => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('department_id')
                    ->label('Departemen')
                    ->relationship('department', 'name'),
                SelectFilter::make('branch_id')
                    ->label('Cabang')
                    ->relationship('branch', 'name'),
                SelectFilter::make('employment_status')
                    ->label('Status Kerja')
                    ->options(collect(EmploymentStatus::cases())->mapWithKeys(
                        static fn (EmploymentStatus $status): array => [$status->value => $status->label()],
                    )->all()),
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
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }
}
