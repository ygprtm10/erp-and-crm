<?php

declare(strict_types=1);

namespace Modules\Transaction\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Branch;
use Modules\Transaction\Enums\PosTableStatus;

/**
 * Represents a physical dining or service table with a unique QR ordering token.
 *
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property string $table_number
 * @property string $qr_token
 * @property int $capacity
 * @property PosTableStatus $status
 */
class PosTable extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pos_tables';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'table_number',
        'qr_token',
        'capacity',
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
            'capacity' => 'integer',
            'status' => PosTableStatus::class,
        ];
    }

    /**
     * Define the branch where this table is located.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Define orders associated with this table.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'pos_table_id');
    }
}
