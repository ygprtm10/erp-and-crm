<?php

declare(strict_types=1);

namespace Modules\Transaction\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Accounting\Models\Account;
use Modules\Transaction\Enums\PaymentGatewayMode;
use Modules\Transaction\Enums\PaymentMethod;
use Modules\Transaction\Enums\PaymentTransactionStatus;

/**
 * Represents a manual or Midtrans payment record settled against an order.
 *
 * @property int $id
 * @property int $company_id
 * @property int $order_id
 * @property int|null $account_id
 * @property PaymentMethod $payment_method
 * @property PaymentGatewayMode $gateway_mode
 * @property string $amount
 * @property string $gateway_fee_amount
 * @property string $platform_fee_amount
 * @property string $net_amount
 * @property Carbon|null $paid_at
 * @property string|null $gateway_reference
 * @property PaymentTransactionStatus $status
 */
class Payment extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'trx_payments';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'order_id',
        'account_id',
        'payment_method',
        'gateway_mode',
        'amount',
        'gateway_fee_amount',
        'platform_fee_amount',
        'net_amount',
        'paid_at',
        'gateway_reference',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'gateway_mode' => PaymentGatewayMode::class,
            'amount' => 'decimal:4',
            'gateway_fee_amount' => 'decimal:4',
            'platform_fee_amount' => 'decimal:4',
            'net_amount' => 'decimal:4',
            'paid_at' => 'datetime',
            'status' => PaymentTransactionStatus::class,
        ];
    }

    /**
     * Define the parent order settled by this payment.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Define the optional Accounting cash/bank account receiving this payment.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
