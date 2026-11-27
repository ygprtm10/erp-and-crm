<?php

declare(strict_types=1);

namespace Modules\Transaction\Models;

use App\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log recording order lifecycle events and raw payment gateway payloads.
 *
 * @property int $id
 * @property int $company_id
 * @property int $order_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property int|null $created_by
 */
class OrderLog extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'trx_order_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'order_id',
        'event',
        'payload',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * Define the parent order associated with this audit log entry.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Define the optional user who triggered this order event.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
