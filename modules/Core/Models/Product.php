<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Modules\Core\Enums\ProductType;

/**
 * Master catalog product supporting goods, F&B menu, services, and memberships.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $category_id
 * @property string $name
 * @property string $slug
 * @property ProductType $type
 * @property bool $track_stock
 * @property bool $show_on_web
 * @property string|null $image_path
 * @property int|null $income_account_id
 * @property int|null $cogs_account_id
 * @property int|null $inventory_account_id
 */
class Product extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'core_products';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'category_id',
        'name',
        'slug',
        'type',
        'track_stock',
        'show_on_web',
        'image_path',
        'income_account_id',
        'cogs_account_id',
        'inventory_account_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'track_stock' => 'boolean',
            'show_on_web' => 'boolean',
        ];
    }

    /**
     * Define the category to which this product belongs.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Define purchasable/sellable SKU variants belonging to this product.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id');
    }

    /**
     * Define promotional discounts attached to variants of this product.
     */
    public function discounts(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProductDiscount::class,
            ProductVariant::class,
            'product_id',
            'product_variant_id',
        );
    }
}
