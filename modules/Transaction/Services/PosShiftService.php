<?php

declare(strict_types=1);

namespace Modules\Transaction\Services;

use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Models\Branch;
use Modules\Transaction\Enums\PaymentMethod;
use Modules\Transaction\Enums\PaymentTransactionStatus;
use Modules\Transaction\Enums\PosShiftStatus;
use Modules\Transaction\Models\Payment;
use Modules\Transaction\Models\PosShift;

/**
 * Manages cashier shift opening, duplicate shift prevention, and bcmath cash reconciliation on close.
 */
class PosShiftService
{
    private const MONEY_SCALE = 4;

    /**
     * Open a new cashier shift while preventing concurrent open shifts for the same user and branch.
     */
    public function openShift(
        Company $company,
        Branch $branch,
        User $user,
        string $openingCash = '0.0000',
    ): PosShift {
        if ((int) $branch->company_id !== (int) $company->getKey()) {
            throw new InvalidArgumentException('Branch does not belong to the specified company.');
        }

        $normalizedOpeningCash = $this->normalizeNonNegativeMoney($openingCash, 'opening_cash');

        return DB::transaction(function () use ($company, $branch, $user, $normalizedOpeningCash): PosShift {
            $hasOpenShift = PosShift::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $company->getKey())
                ->where('branch_id', $branch->getKey())
                ->where('user_id', $user->getKey())
                ->where('status', PosShiftStatus::Open->value)
                ->lockForUpdate()
                ->exists();

            if ($hasOpenShift) {
                throw new DomainException('An open cashier shift already exists for this user at the selected branch.');
            }

            return PosShift::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $company->getKey(),
                    'branch_id' => $branch->getKey(),
                    'user_id' => $user->getKey(),
                    'opened_at' => now(),
                    'opening_cash' => $normalizedOpeningCash,
                    'expected_cash' => $normalizedOpeningCash,
                    'status' => PosShiftStatus::Open,
                ]);
        });
    }

    /**
     * Close an active cashier shift and compute expected_cash from opening_cash plus paid cash payments.
     */
    public function closeShift(PosShift $shift, string $actualCash): PosShift
    {
        $normalizedActualCash = $this->normalizeNonNegativeMoney($actualCash, 'actual_cash');

        return DB::transaction(function () use ($shift, $normalizedActualCash): PosShift {
            $lockedShift = PosShift::query()
                ->withoutGlobalScope('company')
                ->whereKey($shift->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedShift instanceof PosShift) {
                throw new DomainException('Cashier shift record not found.');
            }

            if ($lockedShift->status !== PosShiftStatus::Open) {
                throw new DomainException('Only open cashier shifts can be closed.');
            }

            /** Sum all paid cash payments belonging to orders processed under this shift using bcmath. */
            $cashPayments = Payment::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $lockedShift->company_id)
                ->where('payment_method', PaymentMethod::Cash->value)
                ->where('status', PaymentTransactionStatus::Paid->value)
                ->whereHas('order', function ($query) use ($lockedShift): void {
                    $query->withoutGlobalScope('company')
                        ->where('pos_shift_id', $lockedShift->getKey());
                })
                ->pluck('amount');

            $cashReceived = '0.0000';
            foreach ($cashPayments as $amount) {
                $cashReceived = bcadd($cashReceived, bcadd((string) $amount, '0', self::MONEY_SCALE), self::MONEY_SCALE);
            }

            $expectedCash = bcadd(
                bcadd((string) $lockedShift->opening_cash, '0', self::MONEY_SCALE),
                $cashReceived,
                self::MONEY_SCALE,
            );

            $lockedShift->update([
                'closed_at' => now(),
                'expected_cash' => $expectedCash,
                'actual_cash' => $normalizedActualCash,
                'status' => PosShiftStatus::Closed,
            ]);

            $shift->refresh();

            return $lockedShift->refresh();
        });
    }

    /**
     * Validate numeric decimal syntax and ensure the monetary amount is non-negative at scale 4.
     */
    private function normalizeNonNegativeMoney(string $value, string $field): string
    {
        $trimmed = trim($value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException("Invalid decimal format for {$field}.");
        }

        $normalized = bcadd($trimmed, '0', self::MONEY_SCALE);

        if (bccomp($normalized, '0.0000', self::MONEY_SCALE) < 0) {
            throw new InvalidArgumentException("The {$field} cannot be negative.");
        }

        return $normalized;
    }
}
