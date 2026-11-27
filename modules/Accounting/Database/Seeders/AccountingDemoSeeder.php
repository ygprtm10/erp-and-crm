<?php

declare(strict_types=1);

namespace Modules\Accounting\Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;
use Modules\Accounting\Actions\SeedDefaultAccountsAction;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Services\ExpenseService;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Product;

/**
 * Idempotently seeds default COA, product account links, opening journal, and sample expense for Toko Maju.
 */
class AccountingDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->first();

        if (! $tokoMaju instanceof Company) {
            return;
        }

        $companyId = $tokoMaju->getKey();
        $accounts = app(SeedDefaultAccountsAction::class)
            ->execute($tokoMaju)
            ->keyBy('code');

        /** @var Account $cashAccount */
        $cashAccount = $accounts->get('1101');
        /** @var Account $bankAccount */
        $bankAccount = $accounts->get('1102');
        /** @var Account $inventoryAccount */
        $inventoryAccount = $accounts->get('1201');
        /** @var Account $equityAccount */
        $equityAccount = $accounts->get('3101');
        /** @var Account $incomeAccount */
        $incomeAccount = $accounts->get('4101');
        /** @var Account $cogsAccount */
        $cogsAccount = $accounts->get('5101');
        /** @var Account $operationalExpenseAccount */
        $operationalExpenseAccount = $accounts->get('6102');

        /** Link Toko Maju products to standard Revenue, COGS, and Inventory accounts. */
        Product::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->update([
                'income_account_id' => $incomeAccount->getKey(),
                'cogs_account_id' => $cogsAccount->getKey(),
                'inventory_account_id' => $inventoryAccount->getKey(),
            ]);

        $mainBranch = Branch::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('is_main', true)
            ->first();

        /** Create and post opening equity journal once for idempotency. */
        $openingJournalExists = Journal::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('journal_number', 'JRN-SEED-001')
            ->exists();

        if (! $openingJournalExists) {
            app(JournalPostingService::class)->createAndPostJournal(
                company: $tokoMaju,
                journalDate: now()->startOfMonth()->toDateString(),
                description: 'Opening capital deposit for Toko Maju',
                items: [
                    [
                        'account_id' => $bankAccount->getKey(),
                        'debit' => '25000000.0000',
                        'credit' => '0.0000',
                        'memo' => 'Initial capital deposited to Bank',
                    ],
                    [
                        'account_id' => $equityAccount->getKey(),
                        'debit' => '0.0000',
                        'credit' => '25000000.0000',
                        'memo' => 'Owner capital equity',
                    ],
                ],
                branch: $mainBranch,
                journalNumber: 'JRN-SEED-001',
            );
        }

        /** Create and settle one operational expense once so ExpensePaid posts automatically. */
        $seedExpenseExists = Expense::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('expense_number', 'EXP-SEED-001')
            ->exists();

        if (! $seedExpenseExists) {
            $expenseService = app(ExpenseService::class);
            $expense = $expenseService->recordExpense(
                company: $tokoMaju,
                expenseAccount: $operationalExpenseAccount,
                amount: '500000.0000',
                expenseDate: now()->toDateString(),
                branch: $mainBranch,
                notes: 'Monthly store electricity and internet utility',
                expenseNumber: 'EXP-SEED-001',
            );

            $expenseService->payExpense($expense, $cashAccount);
        }
    }
}
