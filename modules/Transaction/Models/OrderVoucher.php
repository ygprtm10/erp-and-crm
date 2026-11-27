<?php

declare(strict_types=1);

namespace Modules\Transaction\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Voucher;

/**
 * Stores a snapshot of a promotional voucher applied to an order.
 *
 * @property int $id
 * @property int $company_id
 * @property int $order_id
 * @property int $voucher_id
 * @property string $voucher_code_snapshot
 * @property string $discount_applied
 */
class OrderVoucher extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'trx_order_vouchers';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'order_id',
        'voucher_id',
        'voucher_code_snapshot',
        'discount_applied',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_applied' => 'decimal:4',
        ];
    }

    /**
     * Define the parent order receiving this voucher discount.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Define the master promotional voucher applied to the order.
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }
}
