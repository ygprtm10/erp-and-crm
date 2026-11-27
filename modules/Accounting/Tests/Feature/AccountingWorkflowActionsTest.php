<?php

declare(strict_types=1);

namespace Modules\Accounting\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Accounting\Database\Seeders\AccountingDemoSeeder;
use Modules\Accounting\Enums\ExpenseStatus;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Filament\Pages\FinancialReportPage;
use Modules\Accounting\Filament\Resources\AccountResource;
use Modules\Accounting\Filament\Resources\AccountResource\Pages\ListAccounts;
use Modules\Accounting\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use Modules\Accounting\Filament\Resources\JournalResource;
use Modules\Accounting\Filament\Resources\JournalResource\Pages\CreateJournal;
use Modules\Accounting\Filament\Resources\JournalResource\Pages\ListJournals;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Services\ExpenseService;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Database\Seeders\CoreDemoSeeder;
use Modules\Platform\Database\Seeders\SaasModuleSeeder;
use Modules\Platform\Database\Seeders\TenantDemoSeeder;
use Tests\TestCase;

/**
 * Feature tests verifying Filament Accounting actions, repeater validation, and report rendering.
 */
class AccountingWorkflowActionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure AccountResource loads standard COA idempotently and protects system accounts from deletion.
     */
    public function test_account_resource_loads_standard_coa_and_blocks_system_account_deletion(): void
    {
        /** Arrange Toko Maju with accounting active. */
        $this->seed([
            SaasModuleSeeder::class,
            TenantDemoSeeder::class,
            CoreDemoSeeder::class,
            AccountingDemoSeeder::class,
        ]);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Act by running Header Action Muat COA Standar again. */
        Livewire::test(ListAccounts::class)
            ->callAction('seedDefaultAccounts')
            ->assertHasNoActionErrors();

        /** Assert still exactly 13 system accounts and system account deletion is denied. */
        $this->assertSame(13, Account::query()->count());
        $systemAccount = Account::query()->where('code', '1101')->firstOrFail();
        $this->assertFalse(AccountResource::canDelete($systemAccount));
    }

    /**
     * Ensure JournalResource validates balanced items, posts drafts, blocks posted edits, and voids with reversal.
     */
    public function test_journal_resource_validates_repeater_balance_and_executes_post_and_void_actions(): void
    {
        /** Arrange Toko Maju accounts. */
        $this->seed([
            SaasModuleSeeder::class,
            TenantDemoSeeder::class,
            CoreDemoSeeder::class,
            AccountingDemoSeeder::class,
        ]);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        $cash = Account::query()->where('code', '1101')->firstOrFail();
        $revenue = Account::query()->where('code', '4101')->firstOrFail();

        /** Attempt 1: Reject unbalanced journal repeater items before saving. */
        Livewire::test(CreateJournal::class)
            ->fillForm([
                'journal_number' => 'JRN-UNBAL-01',
                'journal_date' => now()->toDateString(),
                'description' => 'Unbalanced test entry',
                'items' => [
                    ['account_id' => $cash->getKey(), 'debit' => '100000', 'credit' => '0'],
                    ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => '90000'],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['items']);

        $this->assertNull(Journal::query()->where('journal_number', 'JRN-UNBAL-01')->first());

        /** Attempt 2: Create balanced draft journal via JournalPostingService and post + void via table actions. */
        $draft = app(JournalPostingService::class)->createDraftJournal(
            company: $tokoMaju,
            journalDate: now()->toDateString(),
            description: 'Daily cash sales',
            items: [
                ['account_id' => $cash->getKey(), 'debit' => '300000.0000'],
                ['account_id' => $revenue->getKey(), 'credit' => '300000.0000'],
            ],
            journalNumber: 'JRN-FLOW-01',
        );

        Livewire::test(ListJournals::class)
            ->callAction(TestAction::make('postJournal')->table($draft))
            ->assertHasNoActionErrors();

        $draft->refresh();
        $this->assertSame(JournalStatus::Posted, $draft->status);
        $this->assertFalse(JournalResource::canEdit($draft));
        $this->assertFalse(JournalResource::canDelete($draft));
        $this->get('/app/'.$tokoMaju->slug.'/journals/'.$draft->getKey().'/edit')->assertForbidden();

        /** Void the posted journal via modal action and verify reversing entry is created. */
        Livewire::test(ListJournals::class)
            ->callAction(TestAction::make('voidJournal')->table($draft), data: [
                'reason' => 'Correction required',
            ])
            ->assertHasNoActionErrors();

        $reversal = Journal::query()
            ->where('reference_type', Journal::class)
            ->where('reference_id', $draft->getKey())
            ->firstOrFail();

        $this->assertSame(JournalStatus::Void, $draft->refresh()->status);
        $this->assertSame(JournalStatus::Posted, $reversal->status);
    }

    /**
     * Ensure ExpenseResource markAsPaid action settles unpaid expense and posts an automated journal.
     */
    public function test_expense_resource_mark_as_paid_action_settles_expense_and_posts_journal(): void
    {
        /** Arrange Toko Maju with one unpaid operational expense. */
        $this->seed([
            SaasModuleSeeder::class,
            TenantDemoSeeder::class,
            CoreDemoSeeder::class,
            AccountingDemoSeeder::class,
        ]);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        $bank = Account::query()->where('code', '1102')->firstOrFail();
        $expenseAccount = Account::query()->where('code', '6102')->firstOrFail();

        $expense = app(ExpenseService::class)->recordExpense(
            company: $tokoMaju,
            expenseAccount: $expenseAccount,
            amount: '225000.0000',
            expenseDate: now()->toDateString(),
            notes: 'Delivery courier bill',
            expenseNumber: 'EXP-UI-01',
        );

        /** Act by invoking Tandai Dibayar action from ListExpenses table. */
        Livewire::test(ListExpenses::class)
            ->callAction(TestAction::make('markAsPaid')->table($expense), data: [
                'payment_account_id' => $bank->getKey(),
            ])
            ->assertHasNoActionErrors();

        /** Assert expense is paid and corresponding posted journal exists. */
        $expense->refresh();
        $this->assertSame(ExpenseStatus::Paid, $expense->status);
        $this->assertSame($bank->getKey(), $expense->payment_account_id);
        $this->assertTrue(
            Journal::query()
                ->where('reference_type', Expense::class)
                ->where('reference_id', $expense->getKey())
                ->where('status', JournalStatus::Posted->value)
                ->exists(),
        );
    }

    /**
     * Ensure FinancialReportPage displays Trial Balance and switches cleanly to Profit & Loss tab.
     */
    public function test_financial_report_page_renders_trial_balance_and_profit_and_loss(): void
    {
        /** Arrange seeded Toko Maju with opening capital (25,000,000) and paid expense (500,000). */
        $this->seed([
            SaasModuleSeeder::class,
            TenantDemoSeeder::class,
            CoreDemoSeeder::class,
            AccountingDemoSeeder::class,
        ]);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Assert Trial Balance shows balanced indicator and seeded figures, and P&L tab shows net loss -500k. */
        Livewire::test(FinancialReportPage::class)
            ->assertSee('Seimbang / Balanced')
            ->assertSee('Rp 25.500.000,00')
            ->call('setActiveTab', 'profit_and_loss')
            ->assertSee('Laporan Laba Rugi')
            ->assertSee('-Rp 500.000,00');
    }
}
