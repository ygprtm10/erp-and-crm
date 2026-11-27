<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Enums\ProductUnit;

/**
 * Represents a sellable SKU variant of a catalog product with decimal pricing.
 *
 * @property int $id
 * @property int $company_id
 * @property int $product_id
 * @property string $name
 * @property string $sku
 * @property string|null $barcode
 * @property ProductUnit $unit
 * @property string $cost_price
 * @property string $selling_price
 * @property bool $is_active
 */
class ProductVariant extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'core_product_variants';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'product_id',
        'name',
        'sku',
        'barcode',
        'unit',
        'cost_price',
        'selling_price',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit' => ProductUnit::class,
            'cost_price' => 'decimal:4',
            'selling_price' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Define the parent catalog product for this variant.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Define promotional discounts configured for this variant.
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(ProductDiscount::class, 'product_variant_id');
    }

    /**
     * Define branch-level inventory stock rows for this variant.
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'product_variant_id');
    }
}
