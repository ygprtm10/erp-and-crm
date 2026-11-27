<?php

declare(strict_types=1);

namespace Modules\Kpi\Enums;

/**
 * Defines workflow statuses for an employee KPI evaluation record.
 */
enum KpiEvaluationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Locked = 'locked';
}
