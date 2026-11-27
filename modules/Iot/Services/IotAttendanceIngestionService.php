<?php

declare(strict_types=1);

namespace Modules\Iot\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Hris\Enums\AttendanceSource;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Employee;
use Modules\Hris\Services\AttendanceService;
use Modules\Iot\Enums\IotLogStatus;
use Modules\Iot\Enums\IotScanType;
use Modules\Iot\Models\IotAttendanceLog;
use Modules\Iot\Models\IotDevice;
use Modules\Platform\Models\CompanySetting;

/**
 * Ingests ESP32 RFID/ID tap events with debounce protection, strict tenant isolation, and smart clock-in/out.
 */
class IotAttendanceIngestionService
{
    /**
     * Create a new ingestion service backed by the HRIS attendance service.
     */
    public function __construct(
        private readonly AttendanceService $attendanceService,
    ) {}

    /**
     * Process an incoming card/ID tap from an authenticated ESP32 device and return an OLED-ready JSON array.
     *
     * @param  array<string, mixed>  $rawPayload
     * @return array<string, mixed>
     */
    public function ingestTap(
        IotDevice $device,
        string $uid,
        ?string $deviceTimestamp = null,
        array $rawPayload = [],
    ): array {
        $scannedUid = trim($uid);
        $timezone = $this->resolveTenantTimezone($device->company_id);
        $hasDeviceTimestamp = $deviceTimestamp !== null && trim($deviceTimestamp) !== '';
        $tapTime = $hasDeviceTimestamp
            ? Carbon::parse($deviceTimestamp, $timezone)
            : now($timezone);
        $storedDeviceTimestamp = $hasDeviceTimestamp ? $tapTime : null;

        return DB::transaction(function () use (
            $device,
            $scannedUid,
            $tapTime,
            $storedDeviceTimestamp,
            $rawPayload,
            $timezone,
        ): array {
            /** Step 1: Guard against duplicate accidental taps within the device cooldown window. */
            if ($cooldownLog = $this->findRecentProcessedLogInCooldown($device, $scannedUid, $tapTime, $timezone)) {
                $this->writeLog(
                    device: $device,
                    scannedUid: $scannedUid,
                    scanType: IotScanType::Ignored,
                    status: IotLogStatus::IgnoredCooldown,
                    employeeId: $cooldownLog->employee_id,
                    attendanceId: $cooldownLog->attendance_id,
                    deviceTimestamp: $storedDeviceTimestamp,
                    processedAt: $tapTime,
                    rawPayload: $rawPayload,
                    responseMessage: 'Cooldown active, tap ignored.',
                );

                return [
                    'ok' => true,
                    'code' => 'COOLDOWN_IGNORED',
                    'scan_type' => IotScanType::Ignored->value,
                    'employee_name' => null,
                    'time' => $tapTime->format('H:i:s'),
                    'late_minutes' => 0,
                    'display_line_1' => 'Sudah Tercatat',
                    'display_line_2' => 'Tunggu Sejenak',
                ];
            }

            /** Step 2: Locate an active/probation employee strictly within the device owning company. */
            $employee = Employee::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $device->company_id)
                ->where('rfid_uid', $scannedUid)
                ->whereIn('employment_status', [
                    EmploymentStatus::Active->value,
                    EmploymentStatus::Probation->value,
                ])
                ->first();

            if (! $employee instanceof Employee) {
                $this->writeLog(
                    device: $device,
                    scannedUid: $scannedUid,
                    scanType: IotScanType::Unknown,
                    status: IotLogStatus::UnregisteredUid,
                    employeeId: null,
                    attendanceId: null,
                    deviceTimestamp: $storedDeviceTimestamp,
                    processedAt: $tapTime,
                    rawPayload: $rawPayload,
                    responseMessage: 'Kartu Tidak Terdaftar',
                );

                return [
                    'ok' => false,
                    'code' => 'UNREGISTERED_UID',
                    'scan_type' => IotScanType::Unknown->value,
                    'employee_name' => null,
                    'time' => $tapTime->format('H:i:s'),
                    'late_minutes' => 0,
                    'display_line_1' => 'Kartu Tidak Terdaftar',
                    'display_line_2' => mb_substr($scannedUid, 0, 20),
                ];
            }

            /** Step 3: Determine smart clock-in vs clock-out based on existing HRIS attendance state. */
            $existingAttendance = $this->locateTargetAttendance($device->company_id, $employee, $tapTime);

            if (! $existingAttendance instanceof Attendance || $existingAttendance->clock_in === null) {
                $deviceBranch = $this->resolveDeviceBranch($device);
                $attendance = $this->attendanceService->recordClockIn(
                    employee: $employee,
                    clockInAt: $tapTime,
                    source: AttendanceSource::IotEsp32,
                    branch: $deviceBranch,
                    iotDeviceId: $device->getKey(),
                );

                $displayLineOne = $attendance->late_minutes > 0
                    ? 'Masuk (Telat '.$attendance->late_minutes.'m)'
                    : 'Absen Masuk OK';

                $this->writeLog(
                    device: $device,
                    scannedUid: $scannedUid,
                    scanType: IotScanType::ClockIn,
                    status: IotLogStatus::Processed,
                    employeeId: $employee->getKey(),
                    attendanceId: $attendance->getKey(),
                    deviceTimestamp: $storedDeviceTimestamp,
                    processedAt: $tapTime,
                    rawPayload: $rawPayload,
                    responseMessage: $displayLineOne,
                );

                return [
                    'ok' => true,
                    'code' => 'CLOCK_IN',
                    'scan_type' => IotScanType::ClockIn->value,
                    'employee_name' => $employee->full_name,
                    'time' => $tapTime->format('H:i:s'),
                    'late_minutes' => $attendance->late_minutes,
                    'display_line_1' => $displayLineOne,
                    'display_line_2' => mb_substr($employee->full_name, 0, 20),
                ];
            }

            if ($existingAttendance->clock_out === null) {
                $attendance = $this->attendanceService->recordClockOut(
                    employee: $employee,
                    clockOutAt: $tapTime,
                    source: AttendanceSource::IotEsp32,
                    iotDeviceId: $device->getKey(),
                );

                $this->writeLog(
                    device: $device,
                    scannedUid: $scannedUid,
                    scanType: IotScanType::ClockOut,
                    status: IotLogStatus::Processed,
                    employeeId: $employee->getKey(),
                    attendanceId: $attendance->getKey(),
                    deviceTimestamp: $storedDeviceTimestamp,
                    processedAt: $tapTime,
                    rawPayload: $rawPayload,
                    responseMessage: 'Absen Pulang OK',
                );

                return [
                    'ok' => true,
                    'code' => 'CLOCK_OUT',
                    'scan_type' => IotScanType::ClockOut->value,
                    'employee_name' => $employee->full_name,
                    'time' => $tapTime->format('H:i:s'),
                    'late_minutes' => $attendance->late_minutes,
                    'overtime_minutes' => $attendance->overtime_minutes,
                    'display_line_1' => 'Absen Pulang OK',
                    'display_line_2' => mb_substr($employee->full_name, 0, 20),
                ];
            }

            /** Employee already completed both clock-in and clock-out for this attendance date. */
            $this->writeLog(
                device: $device,
                scannedUid: $scannedUid,
                scanType: IotScanType::Ignored,
                status: IotLogStatus::IgnoredCooldown,
                employeeId: $employee->getKey(),
                attendanceId: $existingAttendance->getKey(),
                deviceTimestamp: $storedDeviceTimestamp,
                processedAt: $tapTime,
                rawPayload: $rawPayload,
                responseMessage: 'Sudah Absen Pulang',
            );

            return [
                'ok' => true,
                'code' => 'ALREADY_COMPLETED',
                'scan_type' => IotScanType::Ignored->value,
                'employee_name' => $employee->full_name,
                'time' => $tapTime->format('H:i:s'),
                'late_minutes' => $existingAttendance->late_minutes,
                'display_line_1' => 'Sudah Absen Pulang',
                'display_line_2' => mb_substr($employee->full_name, 0, 20),
            ];
        });
    }

    /**
     * Find a recently processed scan for the same UID on this device still inside cooldown_seconds.
     */
    private function findRecentProcessedLogInCooldown(
        IotDevice $device,
        string $scannedUid,
        Carbon $tapTime,
        string $timezone,
    ): ?IotAttendanceLog {
        if ($device->cooldown_seconds <= 0) {
            return null;
        }

        $recentLog = IotAttendanceLog::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $device->company_id)
            ->where('iot_device_id', $device->getKey())
            ->where('scanned_uid', $scannedUid)
            ->where('status', IotLogStatus::Processed->value)
            ->latest('id')
            ->first();

        if (! $recentLog instanceof IotAttendanceLog) {
            return null;
        }

        $referenceRaw = $recentLog->device_timestamp ?? $recentLog->processed_at;
        $referenceTime = Carbon::parse($referenceRaw->format('Y-m-d H:i:s'), $timezone);
        $elapsedSeconds = abs($tapTime->getTimestamp() - $referenceTime->getTimestamp());

        if ($elapsedSeconds < $device->cooldown_seconds) {
            return $recentLog;
        }

        return null;
    }

    /**
     * Locate today's attendance record or an open overnight shift attendance from the previous day.
     */
    private function locateTargetAttendance(int $companyId, Employee $employee, Carbon $tapTime): ?Attendance
    {
        $sameDay = Attendance::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', $tapTime->toDateString())
            ->lockForUpdate()
            ->first();

        if ($sameDay instanceof Attendance) {
            return $sameDay;
        }

        $previousDate = $tapTime->copy()->subDay()->toDateString();

        return Attendance::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
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
     * Resolve the tenant's configured timezone or fall back to the application timezone.
     */
    private function resolveTenantTimezone(int $companyId): string
    {
        $timezone = CompanySetting::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->value('timezone');

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('app.timezone', 'Asia/Jakarta');
    }

    /**
     * Load the device's assigned branch bypassing Filament tenant global scope.
     */
    private function resolveDeviceBranch(IotDevice $device): ?Branch
    {
        if ($device->branch_id === null) {
            return null;
        }

        return Branch::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $device->company_id)
            ->find($device->branch_id);
    }

    /**
     * Persist an immutable IoT attendance tap log entry.
     *
     * @param  array<string, mixed>  $rawPayload
     */
    private function writeLog(
        IotDevice $device,
        string $scannedUid,
        IotScanType $scanType,
        IotLogStatus $status,
        ?int $employeeId,
        ?int $attendanceId,
        ?Carbon $deviceTimestamp,
        Carbon $processedAt,
        array $rawPayload,
        string $responseMessage,
    ): IotAttendanceLog {
        return IotAttendanceLog::query()
            ->withoutGlobalScope('company')
            ->create([
                'company_id' => $device->company_id,
                'iot_device_id' => $device->getKey(),
                'employee_id' => $employeeId,
                'attendance_id' => $attendanceId,
                'scanned_uid' => $scannedUid,
                'scan_type' => $scanType,
                'status' => $status,
                'device_timestamp' => $deviceTimestamp,
                'processed_at' => $processedAt,
                'raw_payload' => $rawPayload !== [] ? $rawPayload : null,
                'response_message' => mb_substr($responseMessage, 0, 255),
            ]);
    }
}
