<?php

declare(strict_types=1);

namespace Modules\Iot\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Employee;
use Modules\Iot\Enums\IotLogStatus;
use Modules\Iot\Enums\IotScanType;

/**
 * Tenant-scoped audit log for every RFID/ID tap transmitted by an ESP32 IoT device.
 *
 * @property int $id
 * @property int $company_id
 * @property int $iot_device_id
 * @property int|null $employee_id
 * @property int|null $attendance_id
 * @property string $scanned_uid
 * @property IotScanType $scan_type
 * @property IotLogStatus $status
 * @property Carbon|null $device_timestamp
 * @property Carbon $processed_at
 * @property array<string, mixed>|null $raw_payload
 * @property string|null $response_message
 */
class IotAttendanceLog extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'iot_attendance_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'iot_device_id',
        'employee_id',
        'attendance_id',
        'scanned_uid',
        'scan_type',
        'status',
        'device_timestamp',
        'processed_at',
        'raw_payload',
        'response_message',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scan_type' => IotScanType::class,
            'status' => IotLogStatus::class,
            'device_timestamp' => 'datetime',
            'processed_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    /**
     * Define the ESP32 device that recorded this scan log.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(IotDevice::class, 'iot_device_id');
    }

    /**
     * Define the matched employee when the scanned UID is registered.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Define the HRIS attendance record created or updated by this scan log.
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'attendance_id');
    }
}
