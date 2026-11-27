<?php

declare(strict_types=1);

namespace Modules\Platform\Events;

use App\Models\Company;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a new tenant company is registered on the SaaS platform.
 */
class CompanyRegistered
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance for the registered company.
     */
    public function __construct(
        public readonly Company $company,
    ) {}
}
