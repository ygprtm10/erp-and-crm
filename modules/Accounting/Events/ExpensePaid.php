<?php

declare(strict_types=1);

namespace Modules\Accounting\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Accounting\Models\Expense;

/**
 * Dispatched when an operational expense is settled so general ledger journals are posted automatically.
 */
class ExpensePaid
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance for the settled expense.
     */
    public function __construct(
        public readonly Expense $expense,
        public readonly ?User $paidBy = null,
    ) {}
}
