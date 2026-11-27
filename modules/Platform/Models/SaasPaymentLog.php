<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Enums\GatewayOwner;

/**
 * Stores immutable raw payment gateway request and webhook audit payloads.
 *
 * @property int $id
 * @property int|null $saas_subscription_id
 * @property int|null $trx_order_id
 * @property GatewayOwner $gateway_owner
 * @property string $event
 * @property int|null $http_status
 * @property array<string, mixed>|null $payload
 */
class SaasPaymentLog extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'saas_subscription_id',
        'trx_order_id',
        'gateway_owner',
        'event',
        'http_status',
        'payload',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gateway_owner' => GatewayOwner::class,
            'http_status' => 'integer',
            'payload' => 'array',
        ];
    }

    /**
     * Define the optional SaaS subscription linked to this payment log.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SaasSubscription::class, 'saas_subscription_id');
    }
}
