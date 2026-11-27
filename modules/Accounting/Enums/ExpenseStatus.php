<?php

declare(strict_types=1);

namespace Modules\Accounting\Enums;

/**
 * Tracks settlement state for operational company expenses.
 */
enum ExpenseStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
}
