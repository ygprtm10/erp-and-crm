<?php

declare(strict_types=1);

namespace Modules\Transaction\Enums;

/**
 * Defines aggregate payment settlement statuses on an order.
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
    case Refunded = 'refunded';
}
