<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Core\Enums\DiscountType;

/**
 * Represents a tenant coupon voucher with minimum order threshold and usage quota.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property DiscountType $discount_type
 * @property string $discount_value
 * @property string $min_order_amount
 * @property int $quota
 * @property int $used_count
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
class Voucher extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'core_vouchers';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'code',
        'discount_type',
        'discount_value',
        'min_order_amount',
        'quota',
        'used_count',
        'starts_at',
        'ends_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:4',
            'min_order_amount' => 'decimal:4',
            'quota' => 'integer',
            'used_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
