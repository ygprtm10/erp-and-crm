<?php

declare(strict_types=1);

namespace Modules\Iot\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Branch;
use Modules\Iot\Enums\IotDeviceStatus;
use Modules\Iot\Enums\IotDeviceType;

/**
 * Tenant-scoped ESP32 IoT hardware device with SHA-256 token authentication and heartbeat tracking.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $branch_id
 * @property string $device_code
 * @property string $name
 * @property IotDeviceType $device_type
 * @property string $api_token_hash
 * @property string $token_prefix
 * @property string|null $firmware_version
 * @property string|null $mac_address
 * @property string|null $last_ip_address
 * @property IotDeviceStatus $status
 * @property int $cooldown_seconds
 * @property Carbon|null $last_heartbeat_at
 * @property string|null $notes
 */
class IotDevice extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'iot_devices';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'device_code',
        'name',
        'device_type',
        'api_token_hash',
        'token_prefix',
        'firmware_version',
        'mac_address',
        'last_ip_address',
        'status',
        'cooldown_seconds',
        'last_heartbeat_at',
        'notes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'api_token_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'device_type' => IotDeviceType::class,
            'status' => IotDeviceStatus::class,
            'cooldown_seconds' => 'integer',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    /**
     * Define the optional branch where this IoT device is installed.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Define the tap logs transmitted by this IoT device.
     */
    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(IotAttendanceLog::class, 'iot_device_id');
    }

    /**
     * Determine whether this device has sent a heartbeat within the given threshold window.
     */
    public function isOnline(int $thresholdMinutes = 5): bool
    {
        if ($this->last_heartbeat_at === null) {
            return false;
        }

        return $this->last_heartbeat_at->greaterThanOrEqualTo(now()->subMinutes($thresholdMinutes));
    }
}
