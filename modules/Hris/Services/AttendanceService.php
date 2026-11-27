<?php

declare(strict_types=1);

namespace Modules\Hris\Services;

use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Models\Branch;
use Modules\Hris\Enums\AttendanceSource;
use Modules\Hris\Enums\AttendanceStatus;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\WorkShift;

/**
 * Handles employee clock-in and clock-out attendance recording across manual, web, and IoT ESP32 sources.
 */
class AttendanceService
{
    /**
     * Record an employee clock-in event, evaluating shift lateness and tolerance minutes.
     */
    public function recordClockIn(
        Employee $employee,
        CarbonInterface|string $clockInAt,
        AttendanceSource $source = AttendanceSource::Manual,
        ?Branch $branch = null,
        ?WorkShift $shift = null,
        ?int $iotDeviceId = null,
        ?string $notes = null,
    ): Attendance {
        $clockIn = $this->parseDateTime($clockInAt);

        if ($branch instanceof Branch && (int) $branch->company_id !== (int) $employee->company_id) {
            throw new InvalidArgumentException('Branch does not belong to the employee company.');
        }

        if ($shift instanceof WorkShift && (int) $shift->company_id !== (int) $employee->company_id) {
            throw new InvalidArgumentException('Work shift does not belong to the employee company.');
        }

        $effectiveShift = $shift ?? $this->resolveEmployeeShift($employee);
        $effectiveBranchId = $branch?->getKey() ?? $employee->branch_id;
        $attendanceDate = $clockIn->toDateString();

        [$status, $lateMinutes] = $this->evaluateClockInStatus($clockIn, $attendanceDate, $effectiveShift);

        return DB::transaction(function () use (
            $employee,
            $effectiveBranchId,
            $effectiveShift,
            $attendanceDate,
            $clockIn,
            $lateMinutes,
            $status,
            $source,
            $iotDeviceId,
            $notes,
        ): Attendance {
            $existing = Attendance::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->getKey())
                ->whereDate('attendance_date', $attendanceDate)
                ->lockForUpdate()
                ->first();

            /** Preserve an earlier automated tap unless overridden manually or missing a clock-in timestamp. */
            if ($existing instanceof Attendance && $existing->clock_in !== null && $source !== AttendanceSource::Manual && $clockIn->greaterThanOrEqualTo($existing->clock_in)) {
                return $existing;
            }

            $attributes = [
                'branch_id' => $effectiveBranchId,
                'work_shift_id' => $effectiveShift?->getKey(),
                'clock_in' => $clockIn,
                'late_minutes' => $lateMinutes,
                'status' => $status,
                'source' => $source,
                'iot_device_id' => $iotDeviceId,
                'notes' => $notes,
            ];

            if ($existing instanceof Attendance) {
                $existing->update($attributes);

                return $existing->refresh();
            }

            return Attendance::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $employee->company_id,
                    'employee_id' => $employee->getKey(),
                    'attendance_date' => $attendanceDate,
                    ...$attributes,
                ]);
        });
    }

    /**
     * Record an employee clock-out event and calculate shift overtime minutes.
     */
    public function recordClockOut(
        Employee $employee,
        CarbonInterface|string $clockOutAt,
        AttendanceSource $source = AttendanceSource::Manual,
        ?int $iotDeviceId = null,
        ?string $notes = null,
    ): Attendance {
        $clockOut = $this->parseDateTime($clockOutAt);

        return DB::transaction(function () use ($employee, $clockOut, $source, $iotDeviceId, $notes): Attendance {
            $attendance = $this->locateClockOutTargetAttendance($employee, $clockOut);

            if (! $attendance instanceof Attendance || $attendance->clock_in === null) {
                throw new DomainException('No clock-in attendance record found for the specified date.');
            }

            if ($clockOut->lessThan($attendance->clock_in)) {
                throw new DomainException('Clock-out timestamp cannot be earlier than clock-in timestamp.');
            }

            $shift = $attendance->workShift()->withoutGlobalScope('company')->first()
                ?? $this->resolveEmployeeShift($employee);

            $overtimeMinutes = $this->calculateOvertimeMinutes(
                clockOut: $clockOut,
                attendanceDate: $attendance->attendance_date->toDateString(),
                shift: $shift,
            );

            $attendance->update([
                'clock_out' => $clockOut,
                'overtime_minutes' => $overtimeMinutes,
                'source' => $source,
                'iot_device_id' => $iotDeviceId ?? $attendance->iot_device_id,
                'notes' => $notes ?? $attendance->notes,
            ]);

            return $attendance->refresh();
        });
    }

    /**
     * Locate today's attendance record or fallback to an open overnight shift from the previous date.
     */
    private function locateClockOutTargetAttendance(Employee $employee, Carbon $clockOut): ?Attendance
    {
        $sameDay = Attendance::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', $clockOut->toDateString())
            ->lockForUpdate()
            ->first();

        if ($sameDay instanceof Attendance && $sameDay->clock_in !== null) {
            return $sameDay;
        }

        $previousDate = $clockOut->copy()->subDay()->toDateString();

        return Attendance::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', $previousDate)
            ->whereNull('clock_out')
            ->whereHas('workShift', static function ($query): void {
                $query->withoutGlobalScope('company')->where('is_overnight', true);
            })
            ->lockForUpdate()
            ->first();
    }

    /**
     * Evaluate whether clock-in exceeds shift start plus tolerance minutes.
     *
     * @return array{0: AttendanceStatus, 1: int}
     */
    private function evaluateClockInStatus(Carbon $clockIn, string $attendanceDate, ?WorkShift $shift): array
    {
        if (! $shift instanceof WorkShift) {
            return [AttendanceStatus::Present, 0];
        }

        $shiftStart = Carbon::parse($attendanceDate.' '.$shift->clock_in_time, $clockIn->getTimezone());

        if ($clockIn->lessThanOrEqualTo($shiftStart)) {
            return [AttendanceStatus::Present, 0];
        }

        $minutesAfterStart = (int) $shiftStart->diffInMinutes($clockIn);

        if ($minutesAfterStart <= $shift->late_tolerance_minutes) {
            return [AttendanceStatus::Present, 0];
        }

        return [AttendanceStatus::Late, $minutesAfterStart];
    }

    /**
     * Compute overtime minutes beyond the scheduled shift clock-out boundary.
     */
    private function calculateOvertimeMinutes(Carbon $clockOut, string $attendanceDate, ?WorkShift $shift): int
    {
        if (! $shift instanceof WorkShift) {
            return 0;
        }

        $shiftEnd = Carbon::parse($attendanceDate.' '.$shift->clock_out_time, $clockOut->getTimezone());

        if ($shift->is_overnight) {
            $shiftEnd = $shiftEnd->addDay();
        }

        if ($clockOut->lessThanOrEqualTo($shiftEnd)) {
            return 0;
        }

        return (int) $shiftEnd->diffInMinutes($clockOut);
    }

    /**
     * Resolve the employee's default work shift bypassing global tenant scopes.
     */
    private function resolveEmployeeShift(Employee $employee): ?WorkShift
    {
        if ($employee->work_shift_id === null) {
            return null;
        }

        return WorkShift::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $employee->company_id)
            ->find($employee->work_shift_id);
    }

    /**
     * Parse an incoming date-time value into a mutable Carbon instance.
     */
    private function parseDateTime(CarbonInterface|string $value): Carbon
    {
        return $value instanceof CarbonInterface
            ? Carbon::instance($value->toDateTimeImmutable())
            : Carbon::parse($value);
    }
}
