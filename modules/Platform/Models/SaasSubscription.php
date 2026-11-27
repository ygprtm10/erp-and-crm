<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Enums\SubscriptionStatus;

/**
 * Represents a SaaS bundle or add-on module checkout paid to the Platform Midtrans gateway.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $bundle_id
 * @property string $order_number
 * @property array<int, int>|null $module_ids
 * @property string $amount
 * @property SubscriptionStatus $status
 * @property string|null $midtrans_snap_token
 * @property Carbon|null $paid_at
 */
class SaasSubscription extends Model
{
    use BelongsToCompany;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'bundle_id',
        'order_number',
        'module_ids',
        'amount',
        'status',
        'midtrans_snap_token',
        'paid_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'module_ids' => 'array',
            'amount' => 'decimal:4',
            'status' => SubscriptionStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Define the optional bundle purchased in this subscription checkout.
     */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(SaasBundle::class, 'bundle_id');
    }
}
