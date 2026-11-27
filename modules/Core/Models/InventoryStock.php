<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tracks current inventory quantity and minimum alert threshold per branch and variant.
 *
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $product_variant_id
 * @property string $quantity
 * @property string $min_stock_alert
 */
class InventoryStock extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'inv_stocks';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'product_variant_id',
        'quantity',
        'min_stock_alert',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'min_stock_alert' => 'decimal:2',
        ];
    }

    /**
     * Define the branch holding this stock balance.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Define the product variant tracked by this stock row.
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Define the stock movement audit trail scoped to this branch and variant.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_variant_id', 'product_variant_id')
            ->where('branch_id', $this->branch_id);
    }
}
