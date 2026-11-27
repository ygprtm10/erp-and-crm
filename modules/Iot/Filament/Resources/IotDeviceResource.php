<?php

declare(strict_types=1);

namespace Modules\Iot\Filament\Resources;

use App\Concerns\RequiresModule;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Iot\Enums\IotDeviceStatus;
use Modules\Iot\Enums\IotDeviceType;
use Modules\Iot\Filament\Resources\IotDeviceResource\Pages\CreateIotDevice;
use Modules\Iot\Filament\Resources\IotDeviceResource\Pages\EditIotDevice;
use Modules\Iot\Filament\Resources\IotDeviceResource\Pages\ListIotDevices;
use Modules\Iot\Models\IotDevice;
use Modules\Iot\Services\DeviceProvisioningService;
use Modules\Iot\Services\IotAttendanceIngestionService;
use UnitEnum;

/**
 * Tenant-scoped Filament resource for managing ESP32 IoT devices, token rotation, and tap simulation.
 */
class IotDeviceResource extends Resource
{
    use RequiresModule;

    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = IotDevice::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    /**
     * The navigation label displayed in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Perangkat ESP32';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'IoT & Perangkat';

    /**
     * Sort order inside the IoT & Perangkat navigation group.
     */
    protected static ?int $navigationSort = 1;

    /**
     * Return the required module slug controlling access to this resource.
     */
    public static function requiredModuleSlug(): string
    {
        return 'iot';
    }

    /**
     * Define the ESP32 device registration and editing form schema.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Perangkat ESP32')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('device_code')
                            ->label('Kode Perangkat')
                            ->required()
                            ->scopedUnique(ignoreRecord: true)
                            ->maxLength(64)
                            ->placeholder('ESP32-PUSAT-01'),
                        TextInput::make('name')
                            ->label('Nama Perangkat')
                            ->required()
                            ->maxLength(120)
                            ->placeholder('ESP32 Lobby Pusat'),
                        Select::make('branch_id')
                            ->label('Cabang Penempatan')
                            ->relationship('branch', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('device_type')
                            ->label('Tipe Pembaca')
                            ->required()
                            ->default(IotDeviceType::AttendanceRfid->value)
                            ->options(collect(IotDeviceType::cases())->mapWithKeys(
                                static fn (IotDeviceType $type): array => [$type->value => $type->label()],
                            )->all()),
                        TextInput::make('cooldown_seconds')
                            ->label('Jeda Debounce / Cooldown (Detik)')
                            ->numeric()
                            ->required()
                            ->default(60)
                            ->minValue(0)
                            ->maxValue(3600),
                        Select::make('status')
                            ->label('Status Perangkat')
                            ->required()
                            ->default(IotDeviceStatus::Active->value)
                            ->options(collect(IotDeviceStatus::cases())->mapWithKeys(
                                static fn (IotDeviceStatus $status): array => [$status->value => $status->label()],
                            )->all()),
                        TextInput::make('firmware_version')
                            ->label('Versi Firmware')
                            ->maxLength(64)
                            ->nullable(),
                        TextInput::make('mac_address')
                            ->label('MAC Address')
                            ->maxLength(32)
                            ->nullable(),
                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Define the ESP32 devices table columns, filters, and operational actions.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('device_code')
            ->columns([
                TextColumn::make('device_code')
                    ->label('Kode Perangkat')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama Perangkat')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('branch.name')
                    ->label('Cabang')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('device_type')
                    ->label('Tipe')
                    ->badge()
                    ->formatStateUsing(static fn (IotDeviceType $state): string => $state->label()),
                TextColumn::make('token_prefix')
                    ->label('Prefix Token')
                    ->fontFamily('mono')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('connection_state')
                    ->label('Koneksi')
                    ->badge()
                    ->state(static fn (IotDevice $record): string => $record->isOnline() ? 'Online' : 'Offline')
                    ->color(static fn (IotDevice $record): string => $record->isOnline() ? 'success' : 'gray'),
                TextColumn::make('last_heartbeat_at')
                    ->label('Heartbeat Terakhir')
                    ->dateTime('d M Y H:i')
                    ->placeholder('Belum pernah')
                    ->sortable(),
                TextColumn::make('firmware_version')
                    ->label('Firmware')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (IotDeviceStatus $state): string => $state->label())
                    ->color(static fn (IotDeviceStatus $state): string => match ($state) {
                        IotDeviceStatus::Active => 'success',
                        IotDeviceStatus::Inactive => 'danger',
                        IotDeviceStatus::Maintenance => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('branch_id')
                    ->label('Cabang')
                    ->relationship('branch', 'name'),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(IotDeviceStatus::cases())->mapWithKeys(
                        static fn (IotDeviceStatus $status): array => [$status->value => $status->label()],
                    )->all()),
            ])
            ->recordActions([
                static::makeRotateTokenAction(),
                static::makeSimulateTapAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * Build the Table Record Action that rotates the device API token and reveals the new plaintext token once.
     */
    public static function makeRotateTokenAction(): Action
    {
        return Action::make('rotateToken')
            ->label('Rotasi Token API')
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Rotasi Token API Perangkat')
            ->modalDescription('Token lama pada firmware ESP32 akan langsung tidak berlaku setelah rotasi. Pastikan Anda memperbarui firmware dengan token baru.')
            ->modalSubmitActionLabel('Ya, Rotasi Token')
            ->action(static function (IotDevice $record): void {
                $newPlainToken = app(DeviceProvisioningService::class)->rotateToken($record);

                Notification::make()
                    ->title('Token API Baru Berhasil Dibuat')
                    ->body("Salin token baru sekarang (hanya ditampilkan satu kali): {$newPlainToken}")
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Build the Table Record Action that simulates an ESP32 card tap through IotAttendanceIngestionService.
     */
    public static function makeSimulateTapAction(): Action
    {
        return Action::make('simulateTap')
            ->label('Simulasi Tap Kartu')
            ->icon(Heroicon::OutlinedCreditCard)
            ->color('info')
            ->modalHeading('Simulasi Tap Kartu RFID ESP32')
            ->modalDescription('Uji alur tap langsung ke IotAttendanceIngestionService dan HRIS tanpa perangkat fisik.')
            ->schema([
                TextInput::make('scanned_uid')
                    ->label('UID Kartu RFID')
                    ->required()
                    ->maxLength(100)
                    ->placeholder('UID-EMP-001'),
                DateTimePicker::make('device_timestamp')
                    ->label('Waktu Scan (Opsional)')
                    ->nullable(),
            ])
            ->action(static function (IotDevice $record, array $data): void {
                $result = app(IotAttendanceIngestionService::class)->ingestTap(
                    device: $record,
                    uid: (string) $data['scanned_uid'],
                    deviceTimestamp: isset($data['device_timestamp']) && $data['device_timestamp'] !== null
                        ? (string) $data['device_timestamp']
                        : null,
                    rawPayload: [
                        'uid' => (string) $data['scanned_uid'],
                        'simulated' => true,
                    ],
                );

                $notification = Notification::make()
                    ->title((string) ($result['display_line_1'] ?? 'Simulasi Selesai'))
                    ->body((string) ($result['display_line_2'] ?? '').' ('.(string) ($result['code'] ?? '').')');

                if (($result['ok'] ?? false) === true) {
                    $notification->success()->send();

                    return;
                }

                $notification->warning()->send();
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
            'index' => ListIotDevices::route('/'),
            'create' => CreateIotDevice::route('/create'),
            'edit' => EditIotDevice::route('/{record}/edit'),
        ];
    }
}
