<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources;

use App\Concerns\RequiresModule;
use App\Models\Company;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Core\Models\Branch;
use Modules\Hris\Enums\AttendanceSource;
use Modules\Hris\Enums\AttendanceStatus;
use Modules\Hris\Filament\Resources\AttendanceResource\Pages\ListAttendances;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\WorkShift;
use Modules\Hris\Services\AttendanceService;
use UnitEnum;

/**
 * Tenant-scoped Filament resource for attendance logs with AttendanceService clock-in and clock-out actions.
 */
class AttendanceResource extends Resource
{
    use RequiresModule;

    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = Attendance::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    /**
     * The navigation label displayed in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Absensi';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'HRIS & Payroll';

    /**
     * Sort order inside the HRIS & Payroll navigation group.
     */
    protected static ?int $navigationSort = 5;

    /**
     * Return the required module slug controlling access to this resource.
     */
    public static function requiredModuleSlug(): string
    {
        return 'hris';
    }

    /**
     * Enforce clock-in recording strictly via AttendanceService header action.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Prevent raw editing outside AttendanceService actions.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Prevent deleting attendance audit logs.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Define the attendance listing table schema, filters, and service-backed actions.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('attendance_date', 'desc')
            ->columns([
                TextColumn::make('attendance_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('employee.employee_code')
                    ->label('NIP')
                    ->badge()
                    ->searchable(),
                TextColumn::make('employee.full_name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('branch.name')
                    ->label('Cabang')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('clock_in')
                    ->label('Clock-In')
                    ->dateTime('H:i')
                    ->placeholder('-')
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('clock_out')
                    ->label('Clock-Out')
                    ->dateTime('H:i')
                    ->placeholder('-')
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('late_minutes')
                    ->label('Telat (m)')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('overtime_minutes')
                    ->label('Lembur (m)')
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (AttendanceStatus $state): string => ucfirst($state->value))
                    ->color(static fn (AttendanceStatus $state): string => match ($state) {
                        AttendanceStatus::Present => 'success',
                        AttendanceStatus::Late => 'warning',
                        AttendanceStatus::Absent => 'danger',
                        AttendanceStatus::Leave => 'info',
                        AttendanceStatus::Sick => 'gray',
                    }),
                TextColumn::make('source')
                    ->label('Sumber')
                    ->badge()
                    ->formatStateUsing(static fn (AttendanceSource $state): string => match ($state) {
                        AttendanceSource::Manual => 'Manual',
                        AttendanceSource::Web => 'Web',
                        AttendanceSource::IotEsp32 => 'IoT ESP32',
                    })
                    ->color(static fn (AttendanceSource $state): string => match ($state) {
                        AttendanceSource::Manual => 'gray',
                        AttendanceSource::Web => 'info',
                        AttendanceSource::IotEsp32 => 'primary',
                    }),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Karyawan')
                    ->relationship('employee', 'full_name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(AttendanceStatus::cases())->mapWithKeys(
                        static fn (AttendanceStatus $status): array => [$status->value => ucfirst($status->value)],
                    )->all()),
                Filter::make('attendance_date')
                    ->label('Rentang Tanggal')
                    ->schema([
                        DatePicker::make('from')->label('Dari Tanggal'),
                        DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                static fn (Builder $builder, string $date): Builder => $builder->whereDate('attendance_date', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                static fn (Builder $builder, string $date): Builder => $builder->whereDate('attendance_date', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                static::makeClockOutAction(),
            ]);
    }

    /**
     * Build the Header Action for recording employee clock-in via AttendanceService.
     */
    public static function makeClockInAction(): Action
    {
        return Action::make('recordClockIn')
            ->label('Catat Clock-In')
            ->icon(Heroicon::OutlinedArrowRightOnRectangle)
            ->color('primary')
            ->schema([
                Select::make('employee_id')
                    ->label('Karyawan')
                    ->required()
                    ->searchable()
                    ->options(static function (): array {
                        $tenant = Filament::getTenant();

                        if (! $tenant instanceof Company) {
                            return [];
                        }

                        return Employee::query()
                            ->where('company_id', $tenant->getKey())
                            ->orderBy('full_name')
                            ->pluck('full_name', 'id')
                            ->all();
                    }),
                DateTimePicker::make('clock_in_at')
                    ->label('Waktu Clock-In')
                    ->required()
                    ->default(now()),
                Select::make('source')
                    ->label('Sumber')
                    ->required()
                    ->default(AttendanceSource::Manual->value)
                    ->options([
                        AttendanceSource::Manual->value => 'Manual',
                        AttendanceSource::Web->value => 'Web',
                        AttendanceSource::IotEsp32->value => 'IoT ESP32',
                    ]),
                Select::make('branch_id')
                    ->label('Cabang (Opsional)')
                    ->searchable()
                    ->nullable()
                    ->options(static function (): array {
                        $tenant = Filament::getTenant();

                        if (! $tenant instanceof Company) {
                            return [];
                        }

                        return Branch::query()
                            ->where('company_id', $tenant->getKey())
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    }),
                Select::make('work_shift_id')
                    ->label('Shift Kerja (Opsional)')
                    ->searchable()
                    ->nullable()
                    ->options(static function (): array {
                        $tenant = Filament::getTenant();

                        if (! $tenant instanceof Company) {
                            return [];
                        }

                        return WorkShift::query()
                            ->where('company_id', $tenant->getKey())
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    }),
                Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(2),
            ])
            ->action(static function (array $data): void {
                $tenant = Filament::getTenant();

                if (! $tenant instanceof Company) {
                    return;
                }

                $employee = Employee::query()
                    ->where('company_id', $tenant->getKey())
                    ->findOrFail((int) $data['employee_id']);

                $branch = isset($data['branch_id']) && $data['branch_id'] !== null
                    ? Branch::query()->where('company_id', $tenant->getKey())->find((int) $data['branch_id'])
                    : null;

                $shift = isset($data['work_shift_id']) && $data['work_shift_id'] !== null
                    ? WorkShift::query()->where('company_id', $tenant->getKey())->find((int) $data['work_shift_id'])
                    : null;

                try {
                    app(AttendanceService::class)->recordClockIn(
                        employee: $employee,
                        clockInAt: (string) $data['clock_in_at'],
                        source: AttendanceSource::from((string) $data['source']),
                        branch: $branch,
                        shift: $shift,
                        notes: $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title('Clock-in berhasil dicatat.')
                        ->success()
                        ->send();
                } catch (DomainException|InvalidArgumentException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Build the Table Record Action for recording employee clock-out via AttendanceService.
     */
    public static function makeClockOutAction(): Action
    {
        return Action::make('recordClockOut')
            ->label('Catat Clock-Out')
            ->icon(Heroicon::OutlinedArrowLeftOnRectangle)
            ->color('success')
            ->visible(static fn (Attendance $record): bool => $record->clock_in !== null && $record->clock_out === null)
            ->schema([
                DateTimePicker::make('clock_out_at')
                    ->label('Waktu Clock-Out')
                    ->required()
                    ->default(now()),
                Select::make('source')
                    ->label('Sumber')
                    ->required()
                    ->default(AttendanceSource::Manual->value)
                    ->options([
                        AttendanceSource::Manual->value => 'Manual',
                        AttendanceSource::Web->value => 'Web',
                        AttendanceSource::IotEsp32->value => 'IoT ESP32',
                    ]),
                Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(2),
            ])
            ->action(static function (Attendance $record, array $data): void {
                try {
                    $employee = Employee::query()
                        ->where('company_id', $record->company_id)
                        ->findOrFail($record->employee_id);

                    app(AttendanceService::class)->recordClockOut(
                        employee: $employee,
                        clockOutAt: (string) $data['clock_out_at'],
                        source: AttendanceSource::from((string) $data['source']),
                        notes: $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title('Clock-out berhasil dicatat.')
                        ->success()
                        ->send();
                } catch (DomainException|InvalidArgumentException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Register the pages belonging to this resource.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAttendances::route('/'),
        ];
    }
}
