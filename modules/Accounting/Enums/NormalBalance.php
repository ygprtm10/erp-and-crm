<?php

declare(strict_types=1);

namespace Modules\Accounting\Enums;

/**
 * Indicates whether increases to an account are recorded as debit or credit.
 */
enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
