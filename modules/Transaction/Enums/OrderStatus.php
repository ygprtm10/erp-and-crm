<?php

declare(strict_types=1);

namespace Modules\Transaction\Enums;

/**
 * Defines lifecycle fulfilment statuses for an order.
 */
enum OrderStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Cooking = 'cooking';
    case Served = 'served';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
