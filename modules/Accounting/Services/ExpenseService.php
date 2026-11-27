<?php

declare(strict_types=1);

namespace Modules\Accounting\Services;

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Accounting\Enums\ExpenseStatus;
use Modules\Accounting\Events\ExpensePaid;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Expense;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Contact;

/**
 * Manages operational expense creation and settlement transitions emitting ExpensePaid events.
 */
class ExpenseService
{
    private const MONEY_SCALE = 4;

    /**
     * Record a new unpaid operational expense for the given tenant company.
     */
    public function recordExpense(
        Company $company,
        Account $expenseAccount,
        string $amount,
        CarbonInterface|string $expenseDate,
        ?Branch $branch = null,
        ?Contact $contact = null,
        CarbonInterface|string|null $dueDate = null,
        ?string $receiptPath = null,
        ?string $notes = null,
        ?string $expenseNumber = null,
    ): Expense {
        if ((int) $expenseAccount->company_id !== (int) $company->getKey()) {
            throw new InvalidArgumentException('Expense account does not belong to the specified company.');
        }

        if ($branch instanceof Branch && (int) $branch->company_id !== (int) $company->getKey()) {
            throw new InvalidArgumentException('Branch does not belong to the specified company.');
        }

        if ($contact instanceof Contact && (int) $contact->company_id !== (int) $company->getKey()) {
            throw new InvalidArgumentException('Contact does not belong to the specified company.');
        }

        $normalizedAmount = $this->normalizePositiveAmount($amount);
        $formattedExpenseDate = $this->formatDate($expenseDate);
        $formattedDueDate = $dueDate !== null ? $this->formatDate($dueDate) : null;

        return Expense::query()
            ->withoutGlobalScope('company')
            ->create([
                'company_id' => $company->getKey(),
                'branch_id' => $branch?->getKey(),
                'contact_id' => $contact?->getKey(),
                'expense_account_id' => $expenseAccount->getKey(),
                'payment_account_id' => null,
                'expense_number' => $expenseNumber ?? $this->generateExpenseNumber(),
                'amount' => $normalizedAmount,
                'expense_date' => $formattedExpenseDate,
                'due_date' => $formattedDueDate,
                'receipt_path' => $receiptPath,
                'status' => ExpenseStatus::Unpaid,
                'notes' => $notes,
            ]);
    }

    /**
     * Mark an unpaid expense as paid using the chosen payment account and dispatch ExpensePaid.
     */
    public function payExpense(Expense $expense, Account $paymentAccount, ?User $paidBy = null): Expense
    {
        if ((int) $paymentAccount->company_id !== (int) $expense->company_id) {
            throw new InvalidArgumentException('Payment account does not belong to the expense company.');
        }

        $paidExpense = DB::transaction(function () use ($expense, $paymentAccount): Expense {
            $lockedExpense = Expense::query()
                ->withoutGlobalScope('company')
                ->whereKey($expense->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedExpense instanceof Expense) {
                throw new DomainException('Expense record not found.');
            }

            if ($lockedExpense->status === ExpenseStatus::Paid) {
                throw new DomainException('Expense has already been paid.');
            }

            $lockedExpense->update([
                'payment_account_id' => $paymentAccount->getKey(),
                'status' => ExpenseStatus::Paid,
            ]);

            return $lockedExpense->refresh();
        });

        /** Dispatch ExpensePaid after transaction commits so journal posting sees persisted state. */
        ExpensePaid::dispatch($paidExpense, $paidBy);

        return $paidExpense;
    }

    /**
     * Validate and normalize a strictly positive monetary amount to 4 decimal places.
     */
    private function normalizePositiveAmount(string $value): string
    {
        $trimmed = trim($value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException('Invalid decimal format for expense amount.');
        }

        $normalized = bcadd($trimmed, '0', self::MONEY_SCALE);

        if (bccomp($normalized, '0.0000', self::MONEY_SCALE) <= 0) {
            throw new InvalidArgumentException('Expense amount must be greater than zero.');
        }

        return $normalized;
    }

    /**
     * Normalize date inputs to Y-m-d format.
     */
    private function formatDate(CarbonInterface|string $value): string
    {
        return $value instanceof CarbonInterface
            ? $value->toDateString()
            : Carbon::parse($value)->toDateString();
    }

    /**
     * Generate a unique operational expense number prefixed with EXP-.
     */
    private function generateExpenseNumber(): string
    {
        return 'EXP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
    }
}
