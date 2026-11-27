<?php

declare(strict_types=1);

namespace Modules\Iot\Actions;

use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Hris\Models\Employee;
use Modules\Iot\Enums\IotLogStatus;
use Modules\Iot\Models\IotAttendanceLog;

/**
 * Binds an unregistered RFID UID from an IoT scan log to an employee within the same company.
 */
class BindRfidCardAction
{
    /**
     * Link the scanned UID from an unregistered log entry to the given employee.
     */
    public function execute(IotAttendanceLog $log, Employee $employee): Employee
    {
        if ((int) $log->company_id !== (int) $employee->company_id) {
            throw new InvalidArgumentException('IoT log and employee must belong to the same company.');
        }

        if ($log->status !== IotLogStatus::UnregisteredUid) {
            throw new DomainException('Only unregistered UID scan logs can be bound to an employee.');
        }

        $uid = trim($log->scanned_uid);

        if ($uid === '') {
            throw new DomainException('Scanned RFID UID cannot be empty.');
        }

        return DB::transaction(static function () use ($employee, $uid): Employee {
            $duplicateExists = Employee::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $employee->company_id)
                ->where('rfid_uid', $uid)
                ->whereKeyNot($employee->getKey())
                ->lockForUpdate()
                ->exists();

            if ($duplicateExists) {
                throw new DomainException("RFID UID '{$uid}' is already assigned to another employee in this company.");
            }

            $employee->update([
                'rfid_uid' => $uid,
            ]);

            return $employee->refresh();
        });
    }
}
