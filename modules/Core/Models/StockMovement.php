<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Core\Enums\StockMovementType;

/**
 * Append-only inventory movement ledger recording every stock mutation per branch and variant.
 *
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $product_variant_id
 * @property StockMovementType $type
 * @property string $qty_change
 * @property string $unit_cost
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $notes
 */
class StockMovement extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'inv_stock_movements';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'product_variant_id',
        'type',
        'qty_change',
        'unit_cost',
        'reference_type',
        'reference_id',
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
            'type' => StockMovementType::class,
            'qty_change' => 'decimal:2',
            'unit_cost' => 'decimal:4',
        ];
    }

    /**
     * Define the branch where this stock movement occurred.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Define the product variant affected by this movement.
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Define the optional polymorphic source document for this stock movement.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
