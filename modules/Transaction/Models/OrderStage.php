<?php

declare(strict_types=1);

namespace Modules\Transaction\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tracks service or laundry workflow execution stages for an order.
 *
 * @property int $id
 * @property int $company_id
 * @property int $order_id
 * @property int|null $handled_by_employee_id
 * @property string $stage_name
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property string|null $notes
 */
class OrderStage extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'trx_order_stages';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'order_id',
        'handled_by_employee_id',
        'stage_name',
        'started_at',
        'completed_at',
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
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Define the parent order for this workflow stage.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
