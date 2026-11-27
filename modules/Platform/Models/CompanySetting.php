<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Enums\PaymentGatewayMode;

/**
 * Stores per-column tenant configuration including encrypted payment and messaging credentials.
 *
 * @property int $id
 * @property int $company_id
 * @property string $currency
 * @property string $tax_rate
 * @property string $timezone
 * @property string|null $logo_path
 * @property PaymentGatewayMode $payment_gateway_mode
 * @property bool $midtrans_is_production
 * @property string|null $midtrans_merchant_id
 * @property string|null $midtrans_server_key
 * @property string|null $midtrans_client_key
 * @property string|null $fonnte_token
 * @property string $invoice_prefix
 * @property bool $enable_pph21
 * @property bool $enable_bpjs_kesehatan
 * @property bool $enable_bpjs_ketenagakerjaan
 * @property string $bpjs_jkk_rate
 */
class CompanySetting extends Model
{
    use BelongsToCompany;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'currency',
        'tax_rate',
        'timezone',
        'logo_path',
        'payment_gateway_mode',
        'midtrans_is_production',
        'midtrans_merchant_id',
        'midtrans_server_key',
        'midtrans_client_key',
        'fonnte_token',
        'invoice_prefix',
        'enable_pph21',
        'enable_bpjs_kesehatan',
        'enable_bpjs_ketenagakerjaan',
        'bpjs_jkk_rate',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:2',
            'payment_gateway_mode' => PaymentGatewayMode::class,
            'midtrans_is_production' => 'boolean',
            'midtrans_server_key' => 'encrypted',
            'fonnte_token' => 'encrypted',
            'enable_pph21' => 'boolean',
            'enable_bpjs_kesehatan' => 'boolean',
            'enable_bpjs_ketenagakerjaan' => 'boolean',
            'bpjs_jkk_rate' => 'decimal:4',
        ];
    }
}
