<?php

declare(strict_types=1);

namespace Modules\Accounting\Listeners;

use App\Models\Company;
use Modules\Accounting\Events\ExpensePaid;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Models\Branch;

/**
 * Automatically posts a balanced double-entry journal when an expense is paid.
 */
class PostExpenseJournalListener
{
    /**
     * Inject the JournalPostingService dependency.
     */
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /**
     * Post Debit expense_account_id and Credit payment_account_id for the settled expense.
     */
    public function handle(ExpensePaid $event): void
    {
        $expense = $event->expense;

        if ($expense->payment_account_id === null) {
            return;
        }

        /** Skip duplicate journal creation if this expense was already journaled. */
        $alreadyPosted = Journal::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $expense->company_id)
            ->where('reference_type', Expense::class)
            ->where('reference_id', $expense->getKey())
            ->exists();

        if ($alreadyPosted) {
            return;
        }

        $company = Company::query()->find($expense->company_id);

        if (! $company instanceof Company) {
            return;
        }

        $branch = $expense->branch_id !== null
            ? Branch::query()->withoutGlobalScope('company')->find($expense->branch_id)
            : null;

        $this->journalPostingService->createAndPostJournal(
            company: $company,
            journalDate: $expense->expense_date,
            description: 'Payment for expense '.$expense->expense_number,
            items: [
                [
                    'account_id' => (int) $expense->expense_account_id,
                    'debit' => (string) $expense->amount,
                    'credit' => '0.0000',
                    'memo' => $expense->notes ?? $expense->expense_number,
                ],
                [
                    'account_id' => (int) $expense->payment_account_id,
                    'debit' => '0.0000',
                    'credit' => (string) $expense->amount,
                    'memo' => 'Paid via expense '.$expense->expense_number,
                ],
            ],
            branch: $branch,
            createdBy: $event->paidBy,
            referenceType: Expense::class,
            referenceId: (int) $expense->getKey(),
        );
    }
}
