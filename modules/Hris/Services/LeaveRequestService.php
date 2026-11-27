<?php

declare(strict_types=1);

namespace Modules\Hris\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Hris\Enums\AttendanceSource;
use Modules\Hris\Enums\AttendanceStatus;
use Modules\Hris\Enums\LeaveStatus;
use Modules\Hris\Enums\LeaveType;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\LeaveRequest;

/**
 * Manages employee leave submission, rejection, and approval with automatic attendance synchronization.
 */
class LeaveRequestService
{
    /**
     * Submit a new pending leave request for the specified inclusive date range.
     */
    public function submitLeave(
        Employee $employee,
        LeaveType $type,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        string $reason,
    ): LeaveRequest {
        $start = $this->parseDate($startDate);
        $end = $this->parseDate($endDate);

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException('Leave end date cannot be earlier than start date.');
        }

        $trimmedReason = trim($reason);

        if ($trimmedReason === '') {
            throw new InvalidArgumentException('Leave reason is required.');
        }

        $totalDays = (int) $start->diffInDays($end) + 1;

        return LeaveRequest::query()
            ->withoutGlobalScope('company')
            ->create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->getKey(),
                'type' => $type,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'total_days' => $totalDays,
                'reason' => $trimmedReason,
                'status' => LeaveStatus::Pending,
            ]);
    }

    /**
     * Approve a pending leave request and automatically populate daily attendance rows across its date range.
     */
    public function approveLeave(LeaveRequest $leaveRequest, User $approver): LeaveRequest
    {
        return DB::transaction(function () use ($leaveRequest, $approver): LeaveRequest {
            $lockedLeave = LeaveRequest::query()
                ->withoutGlobalScope('company')
                ->whereKey($leaveRequest->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedLeave instanceof LeaveRequest) {
                throw new DomainException('Leave request not found.');
            }

            if ($lockedLeave->status !== LeaveStatus::Pending) {
                throw new DomainException('Only pending leave requests can be approved.');
            }

            $employee = Employee::query()
                ->withoutGlobalScope('company')
                ->findOrFail($lockedLeave->employee_id);

            $lockedLeave->update([
                'status' => LeaveStatus::Approved,
                'approved_by' => $approver->getKey(),
                'approved_at' => now(),
                'rejection_reason' => null,
            ]);

            $attendanceStatus = $lockedLeave->type === LeaveType::Sick
                ? AttendanceStatus::Sick
                : AttendanceStatus::Leave;

            $period = CarbonPeriod::create($lockedLeave->start_date, $lockedLeave->end_date);

            foreach ($period as $date) {
                $dateString = $date->toDateString();
                $attributes = [
                    'branch_id' => $employee->branch_id,
                    'work_shift_id' => $employee->work_shift_id,
                    'clock_in' => null,
                    'clock_out' => null,
                    'late_minutes' => 0,
                    'overtime_minutes' => 0,
                    'status' => $attendanceStatus,
                    'source' => AttendanceSource::Manual,
                    'notes' => 'Leave approved: '.$lockedLeave->reason,
                ];

                $existingAttendance = Attendance::query()
                    ->withoutGlobalScope('company')
                    ->where('company_id', $lockedLeave->company_id)
                    ->where('employee_id', $lockedLeave->employee_id)
                    ->whereDate('attendance_date', $dateString)
                    ->lockForUpdate()
                    ->first();

                if ($existingAttendance instanceof Attendance) {
                    $existingAttendance->update($attributes);

                    continue;
                }

                Attendance::query()
                    ->withoutGlobalScope('company')
                    ->create([
                        'company_id' => $lockedLeave->company_id,
                        'employee_id' => $lockedLeave->employee_id,
                        'attendance_date' => $dateString,
                        ...$attributes,
                    ]);
            }

            return $lockedLeave->refresh();
        });
    }

    /**
     * Reject a pending leave request with a mandatory reason without modifying attendance rows.
     */
    public function rejectLeave(LeaveRequest $leaveRequest, User $approver, string $rejectionReason): LeaveRequest
    {
        $trimmedReason = trim($rejectionReason);

        if ($trimmedReason === '') {
            throw new InvalidArgumentException('Rejection reason is required when rejecting leave.');
        }

        return DB::transaction(function () use ($leaveRequest, $approver, $trimmedReason): LeaveRequest {
            $lockedLeave = LeaveRequest::query()
                ->withoutGlobalScope('company')
                ->whereKey($leaveRequest->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedLeave instanceof LeaveRequest) {
                throw new DomainException('Leave request not found.');
            }

            if ($lockedLeave->status !== LeaveStatus::Pending) {
                throw new DomainException('Only pending leave requests can be rejected.');
            }

            $lockedLeave->update([
                'status' => LeaveStatus::Rejected,
                'approved_by' => $approver->getKey(),
                'approved_at' => now(),
                'rejection_reason' => $trimmedReason,
            ]);

            return $lockedLeave->refresh();
        });
    }

    /**
     * Parse an incoming date value into a start-of-day Carbon instance.
     */
    private function parseDate(CarbonInterface|string $value): Carbon
    {
        return ($value instanceof CarbonInterface
            ? Carbon::instance($value->toDateTimeImmutable())
            : Carbon::parse($value))->startOfDay();
    }
}
