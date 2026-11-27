<?php

declare(strict_types=1);

namespace Modules\Transaction\Enums;

/**
 * Defines settlement states for individual payment attempts in trx_payments.
 */
enum PaymentTransactionStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
