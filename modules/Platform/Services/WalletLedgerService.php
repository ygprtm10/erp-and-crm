<?php

declare(strict_types=1);

namespace Modules\Platform\Services;

use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Platform\Enums\WalletTransactionType;
use Modules\Platform\Enums\WithdrawalStatus;
use Modules\Platform\Events\WithdrawalCompleted;
use Modules\Platform\Models\SaasTenantBankAccount;
use Modules\Platform\Models\SaasTenantWallet;
use Modules\Platform\Models\SaasWalletTransaction;
use Modules\Platform\Models\SaasWithdrawal;

/**
 * Manages escrow wallet credits, withdrawal holds, completions, and rejections with pessimistic locking.
 */
class WalletLedgerService
{
    private const DECIMAL_SCALE = 4;

    /**
     * Credit net settlement funds into the tenant's available balance with an append-only ledger entry.
     */
    public function creditSale(
        Company $company,
        string $amount,
        string $referenceType,
        int $referenceId,
        ?string $description = null,
    ): SaasWalletTransaction {
        $normalizedAmount = $this->normalizePositiveAmount($amount, 'amount');

        return DB::transaction(function () use ($company, $normalizedAmount, $referenceType, $referenceId, $description): SaasWalletTransaction {
            $wallet = $this->lockOrCreateWallet($company);

            $balanceBefore = $this->formatDecimal((string) $wallet->available_balance);
            $balanceAfter = bcadd($balanceBefore, $normalizedAmount, self::DECIMAL_SCALE);
            $totalEarnedAfter = bcadd($this->formatDecimal((string) $wallet->total_earned), $normalizedAmount, self::DECIMAL_SCALE);

            $wallet->update([
                'available_balance' => $balanceAfter,
                'total_earned' => $totalEarnedAfter,
            ]);

            /** Record immutable append-only credit audit row. */
            return SaasWalletTransaction::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $company->getKey(),
                    'wallet_id' => $wallet->getKey(),
                    'type' => WalletTransactionType::CreditSale,
                    'amount' => $normalizedAmount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'description' => $description,
                ]);
        });
    }

    /**
     * Hold available tenant funds in pending_balance and create a withdrawal request.
     */
    public function requestWithdrawal(
        Company $company,
        SaasTenantBankAccount $bankAccount,
        User $requestedBy,
        string $amount,
        string $adminFee = '0.0000',
        ?string $notes = null,
    ): SaasWithdrawal {
        $normalizedAmount = $this->normalizePositiveAmount($amount, 'amount');
        $normalizedFee = $this->normalizeNonNegativeAmount($adminFee, 'admin_fee');

        /** Ensure net payout remains strictly positive after subtracting admin fees. */
        if (bccomp($normalizedAmount, $normalizedFee, self::DECIMAL_SCALE) <= 0) {
            throw new InvalidArgumentException('Withdrawal amount must be greater than the admin fee.');
        }

        /** Prevent cross-tenant bank account usage when submitting a payout request. */
        if ((int) $bankAccount->company_id !== (int) $company->getKey()) {
            throw new InvalidArgumentException('Bank account does not belong to the specified company.');
        }

        $netTransferAmount = bcsub($normalizedAmount, $normalizedFee, self::DECIMAL_SCALE);

        return DB::transaction(function () use ($company, $bankAccount, $requestedBy, $normalizedAmount, $normalizedFee, $netTransferAmount, $notes): SaasWithdrawal {
            $wallet = $this->lockOrCreateWallet($company);
            $availableBefore = $this->formatDecimal((string) $wallet->available_balance);

            if (bccomp($availableBefore, $normalizedAmount, self::DECIMAL_SCALE) < 0) {
                throw new DomainException('Insufficient available wallet balance for withdrawal.');
            }

            $availableAfter = bcsub($availableBefore, $normalizedAmount, self::DECIMAL_SCALE);
            $pendingAfter = bcadd($this->formatDecimal((string) $wallet->pending_balance), $normalizedAmount, self::DECIMAL_SCALE);

            $wallet->update([
                'available_balance' => $availableAfter,
                'pending_balance' => $pendingAfter,
            ]);

            return SaasWithdrawal::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $company->getKey(),
                    'wallet_id' => $wallet->getKey(),
                    'bank_account_id' => $bankAccount->getKey(),
                    'withdrawal_number' => $this->generateWithdrawalNumber(),
                    'amount' => $normalizedAmount,
                    'admin_fee' => $normalizedFee,
                    'net_transfer_amount' => $netTransferAmount,
                    'bank_snapshot' => [
                        'bank_code' => $bankAccount->bank_code,
                        'bank_name' => $bankAccount->bank_name,
                        'account_number' => $bankAccount->account_number,
                        'account_holder_name' => $bankAccount->account_holder_name,
                    ],
                    'status' => WithdrawalStatus::Pending,
                    'requested_by' => $requestedBy->getKey(),
                    'notes' => $notes,
                ]);
        });
    }

    /**
     * Finalize an approved withdrawal, debit pending_balance, append ledger entry, and emit WithdrawalCompleted.
     */
    public function completeWithdrawal(
        SaasWithdrawal $withdrawal,
        User $processor,
        ?string $transferProofPath = null,
        ?string $notes = null,
    ): SaasWithdrawal {
        $completed = DB::transaction(function () use ($withdrawal, $processor, $transferProofPath, $notes): SaasWithdrawal {
            $lockedWithdrawal = $this->lockActionableWithdrawal($withdrawal);
            $wallet = $this->lockExistingWallet((int) $lockedWithdrawal->company_id);

            $amount = $this->formatDecimal((string) $lockedWithdrawal->amount);
            $pendingBefore = $this->formatDecimal((string) $wallet->pending_balance);

            if (bccomp($pendingBefore, $amount, self::DECIMAL_SCALE) < 0) {
                throw new DomainException('Pending wallet balance is lower than the withdrawal amount.');
            }

            $pendingAfter = bcsub($pendingBefore, $amount, self::DECIMAL_SCALE);
            $totalWithdrawnAfter = bcadd($this->formatDecimal((string) $wallet->total_withdrawn), $amount, self::DECIMAL_SCALE);

            $wallet->update([
                'pending_balance' => $pendingAfter,
                'total_withdrawn' => $totalWithdrawnAfter,
            ]);

            $lockedWithdrawal->update([
                'status' => WithdrawalStatus::Completed,
                'processed_by' => $processor->getKey(),
                'processed_at' => now(),
                'transfer_proof_path' => $transferProofPath,
                'notes' => $notes ?? $lockedWithdrawal->notes,
            ]);

            /** Record append-only debit_withdraw audit entry referencing the withdrawal. */
            SaasWalletTransaction::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $lockedWithdrawal->company_id,
                    'wallet_id' => $wallet->getKey(),
                    'type' => WalletTransactionType::DebitWithdraw,
                    'amount' => $amount,
                    'balance_before' => $pendingBefore,
                    'balance_after' => $pendingAfter,
                    'reference_type' => SaasWithdrawal::class,
                    'reference_id' => $lockedWithdrawal->getKey(),
                    'description' => 'Completed withdrawal '.$lockedWithdrawal->withdrawal_number,
                ]);

            return $lockedWithdrawal->refresh();
        });

        /** Dispatch cross-module event only after transaction commits cleanly. */
        WithdrawalCompleted::dispatch($completed);

        return $completed;
    }

    /**
     * Reject an actionable withdrawal and release held funds back to available_balance.
     */
    public function rejectWithdrawal(
        SaasWithdrawal $withdrawal,
        User $processor,
        string $rejectionReason,
    ): SaasWithdrawal {
        if (trim($rejectionReason) === '') {
            throw new InvalidArgumentException('Rejection reason is required.');
        }

        return DB::transaction(function () use ($withdrawal, $processor, $rejectionReason): SaasWithdrawal {
            $lockedWithdrawal = $this->lockActionableWithdrawal($withdrawal);
            $wallet = $this->lockExistingWallet((int) $lockedWithdrawal->company_id);

            $amount = $this->formatDecimal((string) $lockedWithdrawal->amount);
            $pendingBefore = $this->formatDecimal((string) $wallet->pending_balance);

            if (bccomp($pendingBefore, $amount, self::DECIMAL_SCALE) < 0) {
                throw new DomainException('Pending wallet balance is lower than the withdrawal amount.');
            }

            $pendingAfter = bcsub($pendingBefore, $amount, self::DECIMAL_SCALE);
            $availableAfter = bcadd($this->formatDecimal((string) $wallet->available_balance), $amount, self::DECIMAL_SCALE);

            $wallet->update([
                'available_balance' => $availableAfter,
                'pending_balance' => $pendingAfter,
            ]);

            $lockedWithdrawal->update([
                'status' => WithdrawalStatus::Rejected,
                'processed_by' => $processor->getKey(),
                'processed_at' => now(),
                'rejection_reason' => trim($rejectionReason),
            ]);

            return $lockedWithdrawal->refresh();
        });
    }

    /**
     * Lock the company's wallet row for update, provisioning it first if absent.
     */
    private function lockOrCreateWallet(Company $company): SaasTenantWallet
    {
        $wallet = SaasTenantWallet::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->lockForUpdate()
            ->first();

        if ($wallet instanceof SaasTenantWallet) {
            return $wallet;
        }

        SaasTenantWallet::query()
            ->withoutGlobalScope('company')
            ->create([
                'company_id' => $company->getKey(),
                'available_balance' => '0.0000',
                'pending_balance' => '0.0000',
                'total_earned' => '0.0000',
                'total_withdrawn' => '0.0000',
            ]);

        return $this->lockExistingWallet((int) $company->getKey());
    }

    /**
     * Lock an existing tenant wallet row inside the active database transaction.
     */
    private function lockExistingWallet(int $companyId): SaasTenantWallet
    {
        $wallet = SaasTenantWallet::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();

        if (! $wallet instanceof SaasTenantWallet) {
            throw new DomainException('Tenant wallet record not found.');
        }

        return $wallet;
    }

    /**
     * Lock a withdrawal record and verify it is still in an actionable non-final state.
     */
    private function lockActionableWithdrawal(SaasWithdrawal $withdrawal): SaasWithdrawal
    {
        $locked = SaasWithdrawal::query()
            ->withoutGlobalScope('company')
            ->whereKey($withdrawal->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof SaasWithdrawal) {
            throw new DomainException('Withdrawal request not found.');
        }

        $actionableStatuses = [
            WithdrawalStatus::Pending,
            WithdrawalStatus::Approved,
            WithdrawalStatus::Processing,
        ];

        if (! in_array($locked->status, $actionableStatuses, true)) {
            throw new DomainException('Withdrawal request has already been finalized.');
        }

        return $locked;
    }

    /**
     * Validate and normalize a strictly positive decimal string to 4 decimal places.
     */
    private function normalizePositiveAmount(string $value, string $field): string
    {
        $normalized = $this->formatDecimal($value, $field);

        if (bccomp($normalized, '0.0000', self::DECIMAL_SCALE) <= 0) {
            throw new InvalidArgumentException("The {$field} must be greater than zero.");
        }

        return $normalized;
    }

    /**
     * Validate and normalize a non-negative decimal string to 4 decimal places.
     */
    private function normalizeNonNegativeAmount(string $value, string $field): string
    {
        $normalized = $this->formatDecimal($value, $field);

        if (bccomp($normalized, '0.0000', self::DECIMAL_SCALE) < 0) {
            throw new InvalidArgumentException("The {$field} cannot be negative.");
        }

        return $normalized;
    }

    /**
     * Assert numeric decimal syntax and normalize to 4 decimal places using bcmath.
     */
    private function formatDecimal(string $value, string $field = 'amount'): string
    {
        $trimmed = trim($value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException("Invalid decimal format for {$field}.");
        }

        return bcadd($trimmed, '0', self::DECIMAL_SCALE);
    }

    /**
     * Generate a unique withdrawal reference number prefixed with WD-.
     */
    private function generateWithdrawalNumber(): string
    {
        return 'WD-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
    }
}
