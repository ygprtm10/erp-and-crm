<?php

declare(strict_types=1);

namespace Modules\Hris\Models;

use App\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Hris\Enums\LeaveStatus;
use Modules\Hris\Enums\LeaveType;

/**
 * Employee leave request supporting multi-day approval and automatic attendance synchronization.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property LeaveType $type
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int $total_days
 * @property string $reason
 * @property LeaveStatus $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejection_reason
 */
class LeaveRequest extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'hr_leave_requests';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'type',
        'start_date',
        'end_date',
        'total_days',
        'reason',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LeaveType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'total_days' => 'integer',
            'status' => LeaveStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Define the employee requesting leave.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Define the user who approved or rejected this leave request.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
