<?php

declare(strict_types=1);

namespace Modules\Hris\Enums;

/**
 * Line item categories on an employee payslip breakdown.
 */
enum PayslipItemType: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';
    case CompanyContribution = 'company_contribution';
}
