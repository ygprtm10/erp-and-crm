<?php

declare(strict_types=1);

namespace Modules\Iot\Filament\Resources;

use App\Concerns\RequiresModule;
use App\Models\Company;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
use Modules\Hris\Models\Employee;
use Modules\Iot\Actions\BindRfidCardAction;
use Modules\Iot\Enums\IotLogStatus;
use Modules\Iot\Enums\IotScanType;
use Modules\Iot\Filament\Resources\IotAttendanceLogResource\Pages\ListIotAttendanceLogs;
use Modules\Iot\Models\IotAttendanceLog;
use UnitEnum;

/**
 * Read-only tenant-scoped Filament resource for auditing ESP32 tap logs and binding unregistered cards.
 */
class IotAttendanceLogResource extends Resource
{
    use RequiresModule;

    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = IotAttendanceLog::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    /**
     * The navigation label displayed in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Log Tap IoT';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'IoT & Perangkat';

    /**
     * Sort order inside the IoT & Perangkat navigation group.
     */
    protected static ?int $navigationSort = 2;

    /**
     * Return the required module slug controlling access to this resource.
     */
    public static function requiredModuleSlug(): string
    {
        return 'iot';
    }

    /**
     * Disable manual creation so logs remain an immutable hardware audit trail.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Disable manual editing of hardware scan audit logs.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Disable deleting hardware scan audit logs.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Define the read-only IoT tap audit table schema, filters, and card binding action.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('processed_at', 'desc')
            ->columns([
                TextColumn::make('processed_at')
                    ->label('Waktu Proses')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),
                TextColumn::make('device.device_code')
                    ->label('Perangkat')
                    ->badge()
                    ->searchable(),
                TextColumn::make('scanned_uid')
                    ->label('UID Kartu')
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('employee.full_name')
                    ->label('Karyawan')
                    ->placeholder('Belum Tertaut')
                    ->searchable(),
                TextColumn::make('scan_type')
                    ->label('Tipe Scan')
                    ->badge()
                    ->formatStateUsing(static fn (IotScanType $state): string => match ($state) {
                        IotScanType::ClockIn => 'Clock-In',
                        IotScanType::ClockOut => 'Clock-Out',
                        IotScanType::Unknown => 'Unknown',
                        IotScanType::Ignored => 'Ignored',
                    })
                    ->color(static fn (IotScanType $state): string => match ($state) {
                        IotScanType::ClockIn => 'success',
                        IotScanType::ClockOut => 'info',
                        IotScanType::Unknown => 'warning',
                        IotScanType::Ignored => 'gray',
                    }),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (IotLogStatus $state): string => match ($state) {
                        IotLogStatus::Processed => 'Processed',
                        IotLogStatus::UnregisteredUid => 'Unregistered UID',
                        IotLogStatus::IgnoredCooldown => 'Ignored Cooldown',
                        IotLogStatus::Failed => 'Failed',
                    })
                    ->color(static fn (IotLogStatus $state): string => match ($state) {
                        IotLogStatus::Processed => 'success',
                        IotLogStatus::UnregisteredUid => 'warning',
                        IotLogStatus::IgnoredCooldown => 'gray',
                        IotLogStatus::Failed => 'danger',
                    }),
                TextColumn::make('response_message')
                    ->label('Respon Perangkat')
                    ->placeholder('-')
                    ->limit(45),
            ])
            ->filters([
                SelectFilter::make('iot_device_id')
                    ->label('Perangkat')
                    ->relationship('device', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        IotLogStatus::Processed->value => 'Processed',
                        IotLogStatus::UnregisteredUid->value => 'Unregistered UID',
                        IotLogStatus::IgnoredCooldown->value => 'Ignored Cooldown',
                        IotLogStatus::Failed->value => 'Failed',
                    ]),
                SelectFilter::make('scan_type')
                    ->label('Tipe Scan')
                    ->options([
                        IotScanType::ClockIn->value => 'Clock-In',
                        IotScanType::ClockOut->value => 'Clock-Out',
                        IotScanType::Unknown->value => 'Unknown',
                        IotScanType::Ignored->value => 'Ignored',
                    ]),
                Filter::make('processed_at')
                    ->label('Rentang Tanggal')
                    ->schema([
                        DatePicker::make('from')->label('Dari Tanggal'),
                        DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                static fn (Builder $builder, string $date): Builder => $builder->whereDate('processed_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                static fn (Builder $builder, string $date): Builder => $builder->whereDate('processed_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                static::makeBindEmployeeAction(),
            ]);
    }

    /**
     * Build the Table Record Action that binds an unregistered scanned UID to a tenant employee.
     */
    public static function makeBindEmployeeAction(): Action
    {
        return Action::make('bindEmployee')
            ->label('Tautkan ke Karyawan')
            ->icon(Heroicon::OutlinedLink)
            ->color('primary')
            ->visible(static fn (IotAttendanceLog $record): bool => $record->status === IotLogStatus::UnregisteredUid)
            ->modalHeading('Tautkan Kartu RFID ke Karyawan')
            ->modalDescription(static fn (IotAttendanceLog $record): string => "Pilih karyawan aktif untuk ditautkan dengan UID kartu {$record->scanned_uid}.")
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
                            ->get()
                            ->mapWithKeys(static fn (Employee $employee): array => [
                                $employee->getKey() => "{$employee->employee_code} — {$employee->full_name}",
                            ])
                            ->all();
                    }),
            ])
            ->action(static function (IotAttendanceLog $record, array $data): void {
                $tenant = Filament::getTenant();

                if (! $tenant instanceof Company) {
                    return;
                }

                $employee = Employee::query()
                    ->where('company_id', $tenant->getKey())
                    ->findOrFail((int) $data['employee_id']);

                try {
                    app(BindRfidCardAction::class)->execute($record, $employee);

                    Notification::make()
                        ->title("Kartu {$record->scanned_uid} berhasil ditautkan ke {$employee->full_name}.")
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
            'index' => ListIotAttendanceLogs::route('/'),
        ];
    }
}
