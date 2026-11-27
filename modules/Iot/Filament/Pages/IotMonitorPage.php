<?php

declare(strict_types=1);

namespace Modules\Iot\Filament\Pages;

use App\Concerns\RequiresModule;
use App\Models\Company;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Modules\Iot\Enums\IotDeviceStatus;
use Modules\Iot\Enums\IotLogStatus;
use Modules\Iot\Models\IotAttendanceLog;
use Modules\Iot\Models\IotDevice;
use UnitEnum;

/**
 * Displays real-time IoT device connectivity status, tap metrics, and ESP32 REST API integration guide.
 */
class IotMonitorPage extends Page
{
    use RequiresModule;

    /**
     * The Blade view rendered by this Filament page.
     */
    protected string $view = 'iot::filament.pages.iot-monitor';

    /**
     * The sidebar navigation icon for this page.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    /**
     * The navigation label shown in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Monitor & Panduan IoT';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'IoT & Perangkat';

    /**
     * Sort order inside the IoT & Perangkat navigation group.
     */
    protected static ?int $navigationSort = 3;

    /**
     * The page title displayed in the header.
     */
    protected static ?string $title = 'Monitor Perangkat & Panduan ESP32';

    /**
     * Return the required module slug controlling access to this page.
     */
    public static function requiredModuleSlug(): string
    {
        return 'iot';
    }

    /**
     * Return aggregated IoT device and tap metrics for the active tenant company.
     *
     * @return array{
     *     total_devices: int,
     *     online_devices: int,
     *     offline_devices: int,
     *     taps_today: int,
     *     unregistered_count: int
     * }
     */
    #[Computed]
    public function summaryMetrics(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return [
                'total_devices' => 0,
                'online_devices' => 0,
                'offline_devices' => 0,
                'taps_today' => 0,
                'unregistered_count' => 0,
            ];
        }

        $devices = IotDevice::query()
            ->where('company_id', $tenant->getKey())
            ->get();

        $onlineCount = $devices->filter(static fn (IotDevice $device): bool => $device->isOnline())->count();
        $totalCount = $devices->count();

        $tapsToday = IotAttendanceLog::query()
            ->where('company_id', $tenant->getKey())
            ->whereDate('processed_at', now()->toDateString())
            ->count();

        $unregisteredCount = IotAttendanceLog::query()
            ->where('company_id', $tenant->getKey())
            ->where('status', IotLogStatus::UnregisteredUid->value)
            ->count();

        return [
            'total_devices' => $totalCount,
            'online_devices' => $onlineCount,
            'offline_devices' => max(0, $totalCount - $onlineCount),
            'taps_today' => $tapsToday,
            'unregistered_count' => $unregisteredCount,
        ];
    }

    /**
     * Return pre-formatted device status rows for Blade table rendering.
     *
     * @return list<array{
     *     device_code: string,
     *     name: string,
     *     branch_name: string,
     *     status_label: string,
     *     online_label: string,
     *     online_color: string,
     *     last_heartbeat: string,
     *     firmware_version: string
     * }>
     */
    #[Computed]
    public function deviceStatuses(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return [];
        }

        $devices = IotDevice::query()
            ->with('branch')
            ->where('company_id', $tenant->getKey())
            ->where('status', IotDeviceStatus::Active->value)
            ->orderBy('device_code')
            ->get();

        return $devices->map(static function (IotDevice $device): array {
            $online = $device->isOnline();

            return [
                'device_code' => $device->device_code,
                'name' => $device->name,
                'branch_name' => $device->branch?->name ?? '-',
                'status_label' => $device->status->label(),
                'online_label' => $online ? 'Online' : 'Offline',
                'online_color' => $online ? 'success' : 'gray',
                'last_heartbeat' => $device->last_heartbeat_at?->format('d M Y H:i:s') ?? 'Belum pernah',
                'firmware_version' => $device->firmware_version ?? '-',
            ];
        })->values()->all();
    }

    /**
     * Return pre-formatted ESP32 REST API endpoint URLs, headers, and JSON examples for Blade rendering.
     *
     * @return array{
     *     heartbeat_url: string,
     *     tap_url: string,
     *     auth_header: string,
     *     heartbeat_request_json: string,
     *     heartbeat_response_json: string,
     *     tap_request_json: string,
     *     tap_response_json: string
     * }
     */
    #[Computed]
    public function apiGuide(): array
    {
        return [
            'heartbeat_url' => url('/api/v1/iot/heartbeat'),
            'tap_url' => url('/api/v1/iot/attendance/tap'),
            'auth_header' => 'Authorization: Bearer <DEVICE_TOKEN>',
            'heartbeat_request_json' => json_encode([
                'firmware_version' => '1.0.0',
                'mac_address' => 'AA:BB:CC:DD:EE:01',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            'heartbeat_response_json' => json_encode([
                'ok' => true,
                'device_code' => 'ESP32-PUSAT-01',
                'server_time' => '2026-11-27T08:00:00+07:00',
                'cooldown_seconds' => 60,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            'tap_request_json' => json_encode([
                'uid' => 'UID-EMP-001',
                'device_timestamp' => '2026-11-27 08:05:00',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            'tap_response_json' => json_encode([
                'ok' => true,
                'code' => 'CLOCK_IN',
                'scan_type' => 'clock_in',
                'employee_name' => 'Rina Wijaya',
                'time' => '08:05:00',
                'late_minutes' => 0,
                'display_line_1' => 'Absen Masuk OK',
                'display_line_2' => 'Rina Wijaya',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }
}
