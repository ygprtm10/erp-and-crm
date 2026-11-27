<?php

declare(strict_types=1);

namespace Modules\Platform\Actions;

use App\Models\Company;
use InvalidArgumentException;
use Modules\Platform\Enums\PaymentGatewayMode;
use Modules\Platform\Models\CompanySetting;

/**
 * Validates and persists per-column tenant settings including encrypted gateway credentials.
 */
class UpdateCompanySettingsAction
{
    /**
     * Update or create the settings record for the given tenant company.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Company $company, array $attributes): CompanySetting
    {
        $rawMode = $attributes['payment_gateway_mode'] ?? PaymentGatewayMode::PlatformMidtrans;
        $mode = $rawMode instanceof PaymentGatewayMode
            ? $rawMode
            : PaymentGatewayMode::from((string) $rawMode);

        $serverKey = isset($attributes['midtrans_server_key']) ? trim((string) $attributes['midtrans_server_key']) : '';
        $clientKey = isset($attributes['midtrans_client_key']) ? trim((string) $attributes['midtrans_client_key']) : '';

        /** Enforce mandatory Midtrans keys whenever the tenant selects own_midtrans mode. */
        if ($mode === PaymentGatewayMode::OwnMidtrans && ($serverKey === '' || $clientKey === '')) {
            throw new InvalidArgumentException('Midtrans server key and client key are required for own_midtrans mode.');
        }

        $fonnteToken = isset($attributes['fonnte_token']) ? trim((string) $attributes['fonnte_token']) : '';
        $merchantId = isset($attributes['midtrans_merchant_id']) ? trim((string) $attributes['midtrans_merchant_id']) : '';

        $payload = [
            'currency' => strtoupper(trim((string) ($attributes['currency'] ?? 'IDR'))),
            'tax_rate' => bcadd((string) ($attributes['tax_rate'] ?? '0.00'), '0', 2),
            'timezone' => trim((string) ($attributes['timezone'] ?? 'Asia/Jakarta')),
            'invoice_prefix' => trim((string) ($attributes['invoice_prefix'] ?? 'INV-')),
            'fonnte_token' => $fonnteToken !== '' ? $fonnteToken : null,
            'payment_gateway_mode' => $mode,
            'midtrans_is_production' => (bool) ($attributes['midtrans_is_production'] ?? false),
            'midtrans_merchant_id' => $merchantId !== '' ? $merchantId : null,
            'midtrans_server_key' => $serverKey !== '' ? $serverKey : null,
            'midtrans_client_key' => $clientKey !== '' ? $clientKey : null,
        ];

        /** Persist optional HRIS payroll defaults only when provided to preserve existing flags. */
        if (array_key_exists('enable_pph21', $attributes)) {
            $payload['enable_pph21'] = (bool) $attributes['enable_pph21'];
        }

        if (array_key_exists('enable_bpjs_kesehatan', $attributes)) {
            $payload['enable_bpjs_kesehatan'] = (bool) $attributes['enable_bpjs_kesehatan'];
        }

        if (array_key_exists('enable_bpjs_ketenagakerjaan', $attributes)) {
            $payload['enable_bpjs_ketenagakerjaan'] = (bool) $attributes['enable_bpjs_ketenagakerjaan'];
        }

        if (array_key_exists('bpjs_jkk_rate', $attributes)) {
            $payload['bpjs_jkk_rate'] = bcadd((string) $attributes['bpjs_jkk_rate'], '0', 4);
        }

        return CompanySetting::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $company->getKey()],
                $payload,
            );
    }
}
