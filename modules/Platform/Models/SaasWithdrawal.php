<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use App\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Enums\WithdrawalStatus;

/**
 * Tracks tenant escrow withdrawal requests, bank snapshots, and Super Admin disbursement state.
 *
 * @property int $id
 * @property int $company_id
 * @property int $wallet_id
 * @property int $bank_account_id
 * @property string $withdrawal_number
 * @property string $amount
 * @property string $admin_fee
 * @property string $net_transfer_amount
 * @property array<string, mixed> $bank_snapshot
 * @property WithdrawalStatus $status
 * @property int $requested_by
 * @property int|null $processed_by
 * @property Carbon|null $processed_at
 * @property string|null $transfer_proof_path
 * @property string|null $rejection_reason
 * @property string|null $notes
 */
class SaasWithdrawal extends Model
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
        'bank_account_id',
        'withdrawal_number',
        'amount',
        'admin_fee',
        'net_transfer_amount',
        'bank_snapshot',
        'status',
        'requested_by',
        'processed_by',
        'processed_at',
        'transfer_proof_path',
        'rejection_reason',
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
            'amount' => 'decimal:4',
            'admin_fee' => 'decimal:4',
            'net_transfer_amount' => 'decimal:4',
            'bank_snapshot' => 'array',
            'status' => WithdrawalStatus::class,
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Define the wallet debited by this withdrawal request.
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(SaasTenantWallet::class, 'wallet_id');
    }

    /**
     * Define the destination bank account referenced at request creation.
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(SaasTenantBankAccount::class, 'bank_account_id');
    }

    /**
     * Define the tenant user who submitted this withdrawal request.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Define the Super Admin user who completed or rejected this withdrawal.
     */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
