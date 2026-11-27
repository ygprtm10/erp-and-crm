<?php

declare(strict_types=1);

namespace Modules\Kpi\Enums;

/**
 * Defines supported cadence types for KPI evaluation periods.
 */
enum KpiPeriodType: string
{
    case Monthly = 'monthly';
    case TwelveWeek = 'twelve_week';
    case Quarterly = 'quarterly';
    case Annual = 'annual';
}
