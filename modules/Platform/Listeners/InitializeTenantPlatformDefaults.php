<?php

declare(strict_types=1);

namespace Modules\Platform\Listeners;

use Illuminate\Support\Facades\DB;
use Modules\Platform\Enums\PaymentGatewayMode;
use Modules\Platform\Events\CompanyRegistered;
use Modules\Platform\Models\CompanySetting;
use Modules\Platform\Models\SaasTenantWallet;

/**
 * Provisions default Platform escrow wallet and company settings upon company registration.
 */
class InitializeTenantPlatformDefaults
{
    /**
     * Create the initial zero-balance wallet and default platform_midtrans settings row.
     */
    public function handle(CompanyRegistered $event): void
    {
        $companyId = $event->company->getKey();

        DB::transaction(function () use ($companyId): void {
            /** Ensure each registered tenant starts with a zero-balance escrow wallet. */
            SaasTenantWallet::query()
                ->withoutGlobalScope('company')
                ->firstOrCreate(
                    ['company_id' => $companyId],
                    [
                        'available_balance' => '0.0000',
                        'pending_balance' => '0.0000',
                        'total_earned' => '0.0000',
                        'total_withdrawn' => '0.0000',
                    ],
                );

            /** Provision default per-column settings using Platform Midtrans escrow mode. */
            CompanySetting::query()
                ->withoutGlobalScope('company')
                ->firstOrCreate(
                    ['company_id' => $companyId],
                    [
                        'currency' => 'IDR',
                        'tax_rate' => '0.00',
                        'timezone' => 'Asia/Jakarta',
                        'payment_gateway_mode' => PaymentGatewayMode::PlatformMidtrans,
                        'midtrans_is_production' => false,
                        'invoice_prefix' => 'INV-',
                        'enable_pph21' => false,
                        'enable_bpjs_kesehatan' => false,
                        'enable_bpjs_ketenagakerjaan' => false,
                        'bpjs_jkk_rate' => '0.0024',
                    ],
                );
        });
    }
}
