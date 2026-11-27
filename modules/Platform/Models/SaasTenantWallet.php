<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Holds escrow balances for tenant funds collected via Platform Midtrans.
 *
 * @property int $id
 * @property int $company_id
 * @property string $available_balance
 * @property string $pending_balance
 * @property string $total_earned
 * @property string $total_withdrawn
 */
class SaasTenantWallet extends Model
{
    use BelongsToCompany;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'available_balance',
        'pending_balance',
        'total_earned',
        'total_withdrawn',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'available_balance' => 'decimal:4',
            'pending_balance' => 'decimal:4',
            'total_earned' => 'decimal:4',
            'total_withdrawn' => 'decimal:4',
        ];
    }

    /**
     * Define the append-only ledger transactions belonging to this wallet.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(SaasWalletTransaction::class, 'wallet_id');
    }

    /**
     * Define the withdrawal requests drawn against this wallet.
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(SaasWithdrawal::class, 'wallet_id');
    }
}
