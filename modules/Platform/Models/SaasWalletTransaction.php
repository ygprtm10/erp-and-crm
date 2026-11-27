<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Platform\Enums\WalletTransactionType;

/**
 * Append-only ledger entry documenting every mutation on a tenant escrow wallet.
 *
 * @property int $id
 * @property int $company_id
 * @property int $wallet_id
 * @property WalletTransactionType $type
 * @property string $amount
 * @property string $balance_before
 * @property string $balance_after
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $description
 */
class SaasWalletTransaction extends Model
{
    use BelongsToCompany;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'wallet_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            'amount' => 'decimal:4',
            'balance_before' => 'decimal:4',
            'balance_after' => 'decimal:4',
        ];
    }

    /**
     * Define the wallet affected by this ledger entry.
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(SaasTenantWallet::class, 'wallet_id');
    }

    /**
     * Define the polymorphic source record for this ledger transaction.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
