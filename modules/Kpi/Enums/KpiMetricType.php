<?php

declare(strict_types=1);

namespace Modules\Kpi\Enums;

/**
 * Defines supported data sources and calculation modes for KPI indicators.
 */
enum KpiMetricType: string
{
    case SalesRevenue = 'sales_revenue';
    case AttendanceRate = 'attendance_rate';
    case PunctualityRate = 'punctuality_rate';
    case ManualNumeric = 'manual_numeric';
    case ManualPercentage = 'manual_percentage';

    /**
     * Determine whether this metric type is synchronized automatically from other modules.
     */
    public function isAutoSynced(): bool
    {
        return match ($this) {
            self::SalesRevenue, self::AttendanceRate, self::PunctualityRate => true,
            self::ManualNumeric, self::ManualPercentage => false,
        };
    }
}
