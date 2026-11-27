<?php

declare(strict_types=1);

namespace Modules\Accounting\Tests\Unit;

use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Actions\SeedDefaultAccountsAction;
use Modules\Accounting\Enums\ExpenseStatus;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Services\ExpenseService;
use Modules\Platform\Actions\ActivateCompanyModulesAction;
use Modules\Platform\Models\SaasModule;
use Modules\Platform\Models\SaasTenantBankAccount;
use Modules\Platform\Models\SaasWithdrawal;
use Modules\Platform\Services\WalletLedgerService;
use Tests\TestCase;

/**
 * Unit tests verifying automatic journal posting from ExpensePaid and WithdrawalCompleted events.
 */
class ExpenseAndWithdrawalJournalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure paying an expense triggers ExpensePaid and posts a balanced Debit Expense / Credit Cash journal.
     */
    public function test_paying_expense_automatically_posts_balanced_journal_and_blocks_double_payment(): void
    {
        /** Arrange a company with default COA and one unpaid expense. */
        $company = Company::factory()->create();
        $staff = User::factory()->create();
        $accounts = app(SeedDefaultAccountsAction::class)->execute($company)->keyBy('code');

        /** @var Account $cash */
        $cash = $accounts->get('1101');
        /** @var Account $opExpense */
        $opExpense = $accounts->get('6102');

        $expenseService = app(ExpenseService::class);
        $expense = $expenseService->recordExpense(
            company: $company,
            expenseAccount: $opExpense,
            amount: '350000.5000',
            expenseDate: '2026-11-27',
            notes: 'Office stationery',
        );

        $this->assertSame(ExpenseStatus::Unpaid, $expense->status);

        /** Act by settling the expense from Kas (1101). */
        $paidExpense = $expenseService->payExpense($expense, $cash, $staff);

        $journal = Journal::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('reference_type', Expense::class)
            ->where('reference_id', $paidExpense->getKey())
            ->with('items')
            ->firstOrFail();

        $itemsByAccount = $journal->items->keyBy('account_id');

        /** Assert ExpensePaid listener posted Debit 6102 and Credit 1101 accurately. */
        $this->assertSame(ExpenseStatus::Paid, $paidExpense->status);
        $this->assertSame(JournalStatus::Posted, $journal->status);
        $this->assertSame('350000.5000', $itemsByAccount->get($opExpense->getKey())->debit);
        $this->assertSame('350000.5000', $itemsByAccount->get($cash->getKey())->credit);

        /** Assert paying an already paid expense throws DomainException. */
        $this->expectException(DomainException::class);
        $expenseService->payExpense($paidExpense, $cash, $staff);
    }

    /**
     * Ensure WithdrawalCompleted posts Debit Bank (+ fee) / Credit Platform Wallet only when accounting is active.
     */
    public function test_withdrawal_completed_posts_journal_only_for_tenants_with_active_accounting_module(): void
    {
        /** Arrange accounting module, one enabled company, and one disabled company. */
        $accountingModule = SaasModule::query()->create([
            'slug' => 'accounting',
            'name' => 'Accounting',
            'monthly_price' => '149000.0000',
        ]);

        $enabledCompany = Company::factory()->create();
        $disabledCompany = Company::factory()->create();
        $user = User::factory()->create();
        $admin = User::factory()->superAdmin()->create();

        app(ActivateCompanyModulesAction::class)->execute($enabledCompany, [$accountingModule->getKey()]);

        $walletService = app(WalletLedgerService::class);
        $walletService->creditSale($enabledCompany, '500000.0000', 'trx_payments', 1);
        $walletService->creditSale($disabledCompany, '500000.0000', 'trx_payments', 2);

        $enabledBank = SaasTenantBankAccount::query()->withoutGlobalScope('company')->create([
            'company_id' => $enabledCompany->getKey(),
            'bank_code' => '014',
            'bank_name' => 'BCA',
            'account_number' => '111222333',
            'account_holder_name' => 'Enabled Co',
        ]);

        $disabledBank = SaasTenantBankAccount::query()->withoutGlobalScope('company')->create([
            'company_id' => $disabledCompany->getKey(),
            'bank_code' => '014',
            'bank_name' => 'BCA',
            'account_number' => '444555666',
            'account_holder_name' => 'Disabled Co',
        ]);

        /** Act by requesting & completing withdrawals for both tenants (enabled has admin_fee 6,500). */
        $wdEnabled = $walletService->requestWithdrawal($enabledCompany, $enabledBank, $user, '200000.0000', '6500.0000');
        $walletService->completeWithdrawal($wdEnabled, $admin);

        $wdDisabled = $walletService->requestWithdrawal($disabledCompany, $disabledBank, $user, '200000.0000');
        $walletService->completeWithdrawal($wdDisabled, $admin);

        /** Assert enabled company got a 3-line balanced settlement journal, while disabled company got none. */
        $journal = Journal::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $enabledCompany->getKey())
            ->where('reference_type', SaasWithdrawal::class)
            ->where('reference_id', $wdEnabled->getKey())
            ->with('items.account')
            ->firstOrFail();

        $byCode = $journal->items->keyBy(static fn ($item): string => $item->account->code);

        $this->assertSame('193500.0000', $byCode->get('1102')->debit);
        $this->assertSame('6500.0000', $byCode->get('6102')->debit);
        $this->assertSame('200000.0000', $byCode->get('1103')->credit);

        $this->assertSame(0, Journal::query()->withoutGlobalScope('company')->where('company_id', $disabledCompany->getKey())->count());
    }
}
