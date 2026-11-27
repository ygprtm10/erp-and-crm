<?php

declare(strict_types=1);

namespace Modules\Transaction\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Transaction\Models\Order;
use Modules\Transaction\Models\Payment;

/**
 * Dispatched exactly once when an order transitions to full paid settlement.
 */
class OrderPaid
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new OrderPaid domain event instance.
     */
    public function __construct(
        public readonly Order $order,
        public readonly ?Payment $triggeringPayment = null,
    ) {}
}
