<?php

declare(strict_types=1);

namespace Modules\Hris\Enums;

/**
 * Lifecycle workflow statuses for monthly payroll runs.
 */
enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';
}
