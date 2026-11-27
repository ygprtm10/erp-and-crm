<?php

declare(strict_types=1);

namespace Modules\Kpi\Enums;

/**
 * Defines lifecycle statuses for a KPI evaluation period.
 */
enum KpiPeriodStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';
}
