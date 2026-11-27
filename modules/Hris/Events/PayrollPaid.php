<?php

declare(strict_types=1);

namespace Modules\Hris\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Hris\Models\PayrollRun;

/**
 * Dispatched once when an approved monthly payroll run transitions to paid status.
 */
class PayrollPaid
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new PayrollPaid domain event instance.
     */
    public function __construct(
        public readonly PayrollRun $payrollRun,
    ) {}
}
