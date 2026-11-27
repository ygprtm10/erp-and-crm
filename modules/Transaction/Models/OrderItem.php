<?php

declare(strict_types=1);

namespace Modules\Transaction\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\ProductVariant;
use Modules\Transaction\Enums\KitchenStatus;

/**
 * Represents a single order line item with immutable pricing, unit, and cost snapshots.
 *
 * @property int $id
 * @property int $company_id
 * @property int $order_id
 * @property int $product_variant_id
 * @property string $item_name_snapshot
 * @property string $qty
 * @property string $unit_snapshot
 * @property string $unit_price
 * @property string $cost_price_snapshot
 * @property string $discount_amount
 * @property string $subtotal
 * @property KitchenStatus|null $kitchen_status
 * @property string|null $notes
 */
class OrderItem extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'trx_order_items';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'order_id',
        'product_variant_id',
        'item_name_snapshot',
        'qty',
        'unit_snapshot',
        'unit_price',
        'cost_price_snapshot',
        'discount_amount',
        'subtotal',
        'kitchen_status',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'cost_price_snapshot' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'kitchen_status' => KitchenStatus::class,
        ];
    }

    /**
     * Define the parent order containing this item.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Define the catalog product variant referenced by this item.
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
