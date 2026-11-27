<?php

declare(strict_types=1);

namespace Modules\Hris\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Branch;
use Modules\Hris\Enums\AttendanceSource;
use Modules\Hris\Enums\AttendanceStatus;

/**
 * Daily attendance record capturing clock-in/out timestamps, lateness, overtime, and capture source.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property int|null $branch_id
 * @property int|null $work_shift_id
 * @property Carbon $attendance_date
 * @property Carbon|null $clock_in
 * @property Carbon|null $clock_out
 * @property int $late_minutes
 * @property int $overtime_minutes
 * @property AttendanceStatus $status
 * @property AttendanceSource $source
 * @property int|null $iot_device_id
 * @property string|null $notes
 */
class Attendance extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'hr_attendances';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'branch_id',
        'work_shift_id',
        'attendance_date',
        'clock_in',
        'clock_out',
        'late_minutes',
        'overtime_minutes',
        'status',
        'source',
        'iot_device_id',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
            'late_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'status' => AttendanceStatus::class,
            'source' => AttendanceSource::class,
        ];
    }

    /**
     * Define the employee associated with this attendance record.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Define the optional branch where attendance was recorded.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Define the work shift evaluated for this attendance record.
     */
    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class, 'work_shift_id');
    }
}
