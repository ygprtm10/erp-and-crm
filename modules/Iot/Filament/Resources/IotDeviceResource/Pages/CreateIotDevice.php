<?php

declare(strict_types=1);

namespace Modules\Iot\Filament\Resources\IotDeviceResource\Pages;

use App\Models\Company;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Branch;
use Modules\Iot\Enums\IotDeviceStatus;
use Modules\Iot\Enums\IotDeviceType;
use Modules\Iot\Filament\Resources\IotDeviceResource;
use Modules\Iot\Services\DeviceProvisioningService;

/**
 * Provisions a new ESP32 device via DeviceProvisioningService and reveals its plaintext API token once.
 */
class CreateIotDevice extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = IotDeviceResource::class;

    /**
     * Delegate device creation and SHA-256 token hashing to DeviceProvisioningService.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        $branch = isset($data['branch_id']) && $data['branch_id'] !== null
            ? Branch::query()->where('company_id', $tenant->getKey())->find((int) $data['branch_id'])
            : null;

        $result = app(DeviceProvisioningService::class)->registerDevice(
            company: $tenant,
            deviceCode: (string) $data['device_code'],
            name: (string) $data['name'],
            deviceType: IotDeviceType::from((string) ($data['device_type'] ?? IotDeviceType::AttendanceRfid->value)),
            branch: $branch,
            cooldownSeconds: (int) ($data['cooldown_seconds'] ?? 60),
            firmwareVersion: isset($data['firmware_version']) ? (string) $data['firmware_version'] : null,
            macAddress: isset($data['mac_address']) ? (string) $data['mac_address'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
        );

        $selectedStatus = IotDeviceStatus::from((string) ($data['status'] ?? IotDeviceStatus::Active->value));

        if ($selectedStatus !== IotDeviceStatus::Active) {
            $result->device->update(['status' => $selectedStatus]);
        }

        Notification::make()
            ->title('Perangkat ESP32 Berhasil Didaftarkan')
            ->body("Salin API Token berikut sekarang (hanya ditampilkan satu kali): {$result->plainToken}")
            ->success()
            ->persistent()
            ->send();

        return $result->device;
    }

    /**
     * Suppress the default generic saved notification because the one-time token notification is shown.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }
}
