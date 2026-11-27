<?php

declare(strict_types=1);

namespace Modules\Hris\Models;

use App\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Branch;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Enums\PtkpStatus;

/**
 * Tenant-scoped employee master record including compensation, PTKP, RFID readiness, and tax/BPJS flags.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property int|null $branch_id
 * @property int|null $department_id
 * @property int|null $position_id
 * @property int|null $work_shift_id
 * @property string $employee_code
 * @property string $full_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $rfid_uid
 * @property PtkpStatus $ptkp_status
 * @property string $base_salary
 * @property string $fixed_allowance
 * @property bool $enable_pph21
 * @property bool $enable_bpjs_kesehatan
 * @property bool $enable_bpjs_ketenagakerjaan
 * @property EmploymentStatus $employment_status
 * @property Carbon $join_date
 * @property string|null $bank_name
 * @property string|null $bank_account_number
 * @property string|null $bank_account_holder
 */
class Employee extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'hr_employees';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'user_id',
        'branch_id',
        'department_id',
        'position_id',
        'work_shift_id',
        'employee_code',
        'full_name',
        'email',
        'phone',
        'rfid_uid',
        'ptkp_status',
        'base_salary',
        'fixed_allowance',
        'enable_pph21',
        'enable_bpjs_kesehatan',
        'enable_bpjs_ketenagakerjaan',
        'employment_status',
        'join_date',
        'bank_name',
        'bank_account_number',
        'bank_account_holder',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ptkp_status' => PtkpStatus::class,
            'base_salary' => 'decimal:4',
            'fixed_allowance' => 'decimal:4',
            'enable_pph21' => 'boolean',
            'enable_bpjs_kesehatan' => 'boolean',
            'enable_bpjs_ketenagakerjaan' => 'boolean',
            'employment_status' => EmploymentStatus::class,
            'join_date' => 'date',
        ];
    }

    /**
     * Define the optional user account linked to this employee.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Define the optional branch assigned to this employee.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Define the optional department assigned to this employee.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * Define the optional job position held by this employee.
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    /**
     * Define the default work shift assigned to this employee.
     */
    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class, 'work_shift_id');
    }

    /**
     * Define daily attendance records belonging to this employee.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'employee_id');
    }

    /**
     * Define leave requests submitted by this employee.
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'employee_id');
    }

    /**
     * Define monthly payslips issued to this employee.
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'employee_id');
    }
}
