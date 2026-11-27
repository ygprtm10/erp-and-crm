<?php

declare(strict_types=1);

namespace Modules\Platform\Services;

use App\Models\Company;
use Modules\Platform\Data\MidtransCredentials;
use Modules\Platform\Enums\GatewayOwner;
use Modules\Platform\Enums\PaymentGatewayMode;
use Modules\Platform\Models\CompanySetting;
use RuntimeException;

/**
 * Resolves whether customer payments route through Platform Midtrans or the tenant's own Midtrans keys.
 */
class PaymentGatewayResolver
{
    /**
     * Resolve the active Midtrans credentials for customer transactions of the given company.
     */
    public function resolveForCompany(Company $company): MidtransCredentials
    {
        /** Bypass tenant global scope so resolution works in webhooks, queues, and admin actions. */
        $settings = CompanySetting::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->first();

        if ($settings instanceof CompanySetting && $settings->payment_gateway_mode === PaymentGatewayMode::OwnMidtrans) {
            return $this->buildTenantCredentials($settings);
        }

        return $this->resolveForPlatformSubscription();
    }

    /**
     * Resolve the Platform-owned Midtrans credentials used for SaaS subscriptions and escrow mode.
     */
    public function resolveForPlatformSubscription(): MidtransCredentials
    {
        $serverKey = (string) config('services.midtrans.server_key', '');
        $clientKey = (string) config('services.midtrans.client_key', '');
        $merchantId = config('services.midtrans.merchant_id');

        if (trim($serverKey) === '' || trim($clientKey) === '') {
            throw new RuntimeException('Platform Midtrans credentials are not configured.');
        }

        return new MidtransCredentials(
            gatewayOwner: GatewayOwner::Platform,
            isProduction: (bool) config('services.midtrans.is_production', false),
            merchantId: is_string($merchantId) && trim($merchantId) !== '' ? $merchantId : null,
            serverKey: $serverKey,
            clientKey: $clientKey,
        );
    }

    /**
     * Validate and construct tenant-owned Midtrans credentials from company settings.
     */
    private function buildTenantCredentials(CompanySetting $settings): MidtransCredentials
    {
        $serverKey = (string) ($settings->midtrans_server_key ?? '');
        $clientKey = (string) ($settings->midtrans_client_key ?? '');

        if (trim($serverKey) === '' || trim($clientKey) === '') {
            throw new RuntimeException('Tenant Midtrans credentials are incomplete for own_midtrans mode.');
        }

        return new MidtransCredentials(
            gatewayOwner: GatewayOwner::Tenant,
            isProduction: (bool) $settings->midtrans_is_production,
            merchantId: $settings->midtrans_merchant_id,
            serverKey: $serverKey,
            clientKey: $clientKey,
        );
    }
}
