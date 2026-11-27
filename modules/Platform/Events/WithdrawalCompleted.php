<?php

declare(strict_types=1);

namespace Modules\Platform\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Platform\Models\SaasWithdrawal;

/**
 * Dispatched after a tenant withdrawal is completed so Accounting can record bank transfer journals.
 */
class WithdrawalCompleted
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance for the completed withdrawal.
     */
    public function __construct(
        public readonly SaasWithdrawal $withdrawal,
    ) {}
}
