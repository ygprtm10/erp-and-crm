<?php

declare(strict_types=1);

namespace Modules\Kpi\Services;

use InvalidArgumentException;
use Modules\Hris\Enums\AttendanceStatus;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Employee;
use Modules\Kpi\Enums\KpiMetricType;
use Modules\Kpi\Models\KpiPeriod;
use Modules\Transaction\Enums\OrderStatus;
use Modules\Transaction\Enums\PaymentStatus;
use Modules\Transaction\Models\Order;

/**
 * Synchronizes employee KPI actual metric values from Transaction sales and HRIS attendance records.
 */
class KpiMetricSyncService
{
    private const MONEY_SCALE = 4;

    private const DIVISION_SCALE = 8;

    /**
     * Resolve the actual metric value for the given employee and period, or null for manual indicators.
     */
    public function resolveMetricValue(KpiMetricType $type, Employee $employee, KpiPeriod $period): ?string
    {
        if ((int) $employee->company_id !== (int) $period->company_id) {
            throw new InvalidArgumentException('Employee and KPI period must belong to the same company.');
        }

        return match ($type) {
            KpiMetricType::SalesRevenue => $this->resolveSalesRevenue($employee, $period),
            KpiMetricType::AttendanceRate => $this->resolveAttendanceRate($employee, $period),
            KpiMetricType::PunctualityRate => $this->resolvePunctualityRate($employee, $period),
            KpiMetricType::ManualNumeric, KpiMetricType::ManualPercentage => null,
        };
    }

    /**
     * Sum paid non-cancelled order grand totals credited to the employee within the KPI period.
     */
    private function resolveSalesRevenue(Employee $employee, KpiPeriod $period): string
    {
        $orders = Order::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $employee->company_id)
            ->where('salesperson_employee_id', $employee->getKey())
            ->where('payment_status', PaymentStatus::Paid->value)
            ->where('order_status', '!=', OrderStatus::Cancelled->value)
            ->whereDate('created_at', '>=', $period->start_date->toDateString())
            ->whereDate('created_at', '<=', $period->end_date->toDateString())
            ->get(['grand_total']);

        $total = '0.0000';

        foreach ($orders as $order) {
            $total = bcadd($total, (string) $order->grand_total, self::MONEY_SCALE);
        }

        return $total;
    }

    /**
     * Calculate employee attendance percentage ((present + late) / total records * 100) within the period.
     */
    private function resolveAttendanceRate(Employee $employee, KpiPeriod $period): string
    {
        $records = Attendance::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', '>=', $period->start_date->toDateString())
            ->whereDate('attendance_date', '<=', $period->end_date->toDateString())
            ->get(['status']);

        $totalCount = $records->count();

        if ($totalCount === 0) {
            return '0.0000';
        }

        $attendedCount = $records->filter(
            static fn (Attendance $record): bool => in_array(
                $record->status,
                [AttendanceStatus::Present, AttendanceStatus::Late],
                true,
            ),
        )->count();

        return bcmul(
            bcdiv((string) $attendedCount, (string) $totalCount, self::DIVISION_SCALE),
            '100',
            self::MONEY_SCALE,
        );
    }

    /**
     * Calculate employee punctuality percentage (present with zero lateness / (present + late) * 100).
     */
    private function resolvePunctualityRate(Employee $employee, KpiPeriod $period): string
    {
        $records = Attendance::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', '>=', $period->start_date->toDateString())
            ->whereDate('attendance_date', '<=', $period->end_date->toDateString())
            ->whereIn('status', [AttendanceStatus::Present->value, AttendanceStatus::Late->value])
            ->get(['status', 'late_minutes']);

        $attendedCount = $records->count();

        if ($attendedCount === 0) {
            return '0.0000';
        }

        $onTimeCount = $records->filter(
            static fn (Attendance $record): bool => $record->status === AttendanceStatus::Present
                && (int) $record->late_minutes === 0,
        )->count();

        return bcmul(
            bcdiv((string) $onTimeCount, (string) $attendedCount, self::DIVISION_SCALE),
            '100',
            self::MONEY_SCALE,
        );
    }
}
