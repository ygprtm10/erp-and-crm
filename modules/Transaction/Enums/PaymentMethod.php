<?php

declare(strict_types=1);

namespace Modules\Transaction\Enums;

/**
 * Defines supported payment tender methods for customer transactions.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Qris = 'qris';
    case Va = 'va';
    case Transfer = 'transfer';
    case Quota = 'quota';
}
