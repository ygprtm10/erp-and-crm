<?php

declare(strict_types=1);

namespace Modules\Accounting\Enums;

/**
 * Classifies chart-of-accounts entries across standard double-entry accounting categories.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';
}
