<?php

declare(strict_types=1);

namespace Modules\Core\Listeners;

use Modules\Core\Models\Branch;
use Modules\Platform\Events\CompanyRegistered;

/**
 * Provisions a default main branch whenever a new company is registered.
 */
class ProvisionMainBranchForCompany
{
    /**
     * Create the company's primary branch record idempotently.
     */
    public function handle(CompanyRegistered $event): void
    {
        Branch::query()
            ->withoutGlobalScope('company')
            ->firstOrCreate(
                [
                    'company_id' => $event->company->getKey(),
                    'code' => 'MAIN',
                ],
                [
                    'name' => $event->company->name.' - Main Branch',
                    'is_main' => true,
                ],
            );
    }
}
