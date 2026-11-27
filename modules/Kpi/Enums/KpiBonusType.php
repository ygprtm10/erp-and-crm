<?php

declare(strict_types=1);

namespace Modules\Kpi\Enums;

/**
 * Defines bonus calculation formulas supported by KPI bonus tiers.
 */
enum KpiBonusType: string
{
    case PercentageOfBaseSalary = 'percentage_of_base_salary';
    case FixedAmount = 'fixed_amount';
}
