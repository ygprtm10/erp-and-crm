<?php

declare(strict_types=1);

namespace Modules\Accounting\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Accounting\Actions\SeedDefaultAccountsAction;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Filament\AccountingPlugin;
use Modules\Accounting\Filament\Pages\FinancialReportPage;
use Modules\Accounting\Filament\Resources\AccountResource;
use Modules\Accounting\Filament\Resources\AccountResource\Pages\ListAccounts;
use Modules\Accounting\Filament\Resources\ExpenseResource;
use Modules\Accounting\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use Modules\Accounting\Filament\Resources\JournalResource;
use Modules\Accounting\Filament\Resources\JournalResource\Pages\ListJournals;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Services\ExpenseService;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Filament\Resources\ProductResource\Pages\EditProduct;
use Modules\Core\Models\Product;
use Modules\Platform\Actions\ActivateCompanyModulesAction;
use Modules\Platform\Models\SaasModule;
use Tests\TestCase;

/**
 * Feature tests verifying RequiresModule gating, cross-tenant isolation, and ProductResource accounting fields.
 */
class AccountingModuleGatingAndIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure Toko Maju (accounting active) accesses all Accounting UIs while Gym Sehat receives 403 Forbidden.
     */
    public function test_requires_module_allows_toko_maju_and_forbids_gym_sehat(): void
    {
        /** Arrange seeded demo tenants where only Toko Maju has accounting active. */
        $this->seed(DatabaseSeeder::class);

        $appPanel = Filament::getPanel('app');
        $this->assertTrue($appPanel->hasPlugin('accounting'));
        $this->assertSame('accounting', AccountingPlugin::make()->getId());

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $owner2 = User::query()->where('email', 'owner2@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        /** Assert Toko Maju owner can access all Accounting resources and FinancialReportPage. */
        $this->actingAs($owner1);
        Filament::setCurrentPanel($appPanel);
        Filament::setTenant($tokoMaju, isQuiet: true);

        $this->assertTrue(AccountResource::canAccess());
        $this->assertTrue(JournalResource::canAccess());
        $this->assertTrue(ExpenseResource::canAccess());
        $this->assertTrue(FinancialReportPage::canAccess());

        $this->get(AccountResource::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(AccountResource::getUrl('create', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(JournalResource::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(JournalResource::getUrl('create', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(ExpenseResource::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(ExpenseResource::getUrl('create', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(FinancialReportPage::getUrl(['tenant' => $tokoMaju]))->assertOk();

        $this->flushSession();

        /** Assert Gym Sehat owner (without accounting subscription) receives 403 Forbidden on all Accounting routes. */
        $this->actingAs($owner2);
        Filament::setTenant($gymSehat, isQuiet: true);

        $this->assertFalse(AccountResource::canAccess());
        $this->assertFalse(JournalResource::canAccess());
        $this->assertFalse(ExpenseResource::canAccess());
        $this->assertFalse(FinancialReportPage::canAccess());

        $this->get(AccountResource::getUrl('index', ['tenant' => $gymSehat]))->assertForbidden();
        $this->get(JournalResource::getUrl('index', ['tenant' => $gymSehat]))->assertForbidden();
        $this->get(ExpenseResource::getUrl('index', ['tenant' => $gymSehat]))->assertForbidden();
        $this->get(FinancialReportPage::getUrl(['tenant' => $gymSehat]))->assertForbidden();
    }

    /**
     * Ensure accounts, journals, and expenses remain strictly isolated even when both tenants activate accounting.
     */
    public function test_enforces_cross_tenant_isolation_for_accounts_journals_and_expenses(): void
    {
        /** Arrange both Toko Maju and Gym Sehat with active accounting module and independent records. */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        $accountingModule = SaasModule::query()->where('slug', 'accounting')->firstOrFail();
        app(ActivateCompanyModulesAction::class)->execute($gymSehat, [$accountingModule->getKey()]);
        $gymAccounts = app(SeedDefaultAccountsAction::class)->execute($gymSehat)->keyBy('code');

        $gymJournal = app(JournalPostingService::class)->createAndPostJournal(
            company: $gymSehat,
            journalDate: '2026-11-27',
            description: 'Gym Sehat private journal',
            items: [
                ['account_id' => $gymAccounts->get('1101')->getKey(), 'debit' => '500000.0000'],
                ['account_id' => $gymAccounts->get('3101')->getKey(), 'credit' => '500000.0000'],
            ],
        );

        $gymExpense = app(ExpenseService::class)->recordExpense(
            company: $gymSehat,
            expenseAccount: $gymAccounts->get('6102'),
            amount: '150000.0000',
            expenseDate: '2026-11-27',
            notes: 'Gym private utility',
        );

        $gymCashAccount = $gymAccounts->get('1101');
        $tokoCashAccount = Account::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->where('code', '1101')->firstOrFail();
        $tokoJournal = Journal::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $tokoExpense = Expense::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Assert Toko Maju tables show only Toko Maju records and never Gym Sehat records. */
        Livewire::test(ListAccounts::class)->assertCanSeeTableRecords([$tokoCashAccount])->assertCanNotSeeTableRecords([$gymCashAccount]);
        Livewire::test(ListJournals::class)->assertCanSeeTableRecords([$tokoJournal])->assertCanNotSeeTableRecords([$gymJournal]);
        Livewire::test(ListExpenses::class)->assertCanSeeTableRecords([$tokoExpense])->assertCanNotSeeTableRecords([$gymExpense]);

        /** Assert direct edit/view URLs targeting Gym Sehat IDs under Toko Maju return 404 Not Found. */
        $this->get('/app/'.$tokoMaju->slug.'/accounts/'.$gymCashAccount->getKey().'/edit')->assertNotFound();
        $this->get('/app/'.$tokoMaju->slug.'/journals/'.$gymJournal->getKey())->assertNotFound();
        $this->get('/app/'.$tokoMaju->slug.'/expenses/'.$gymExpense->getKey().'/edit')->assertNotFound();
    }

    /**
     * Ensure ProductResource shows accounting mapping fields for Toko Maju and hides them for Gym Sehat.
     */
    public function test_product_resource_shows_accounting_mapping_only_when_accounting_module_is_active(): void
    {
        /** Arrange seeded Toko Maju (accounting active) and Gym Sehat (accounting inactive). */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $owner2 = User::query()->where('email', 'owner2@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        $tokoProduct = Product::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $gymProduct = Product::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->firstOrFail();

        /** Assert Toko Maju sees all 3 accounting mapping fields on EditProduct. */
        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        Livewire::test(EditProduct::class, ['record' => $tokoProduct->getKey()])
            ->assertFormFieldExists('income_account_id')
            ->assertFormFieldExists('cogs_account_id')
            ->assertFormFieldExists('inventory_account_id')
            ->assertFormFieldIsVisible('income_account_id')
            ->assertFormFieldIsVisible('cogs_account_id')
            ->assertFormFieldIsVisible('inventory_account_id');

        $this->flushSession();

        /** Assert Gym Sehat hides all 3 accounting mapping fields on EditProduct. */
        $this->actingAs($owner2);
        Filament::setTenant($gymSehat, isQuiet: true);

        Livewire::test(EditProduct::class, ['record' => $gymProduct->getKey()])
            ->assertFormFieldIsHidden('income_account_id')
            ->assertFormFieldIsHidden('cogs_account_id')
            ->assertFormFieldIsHidden('inventory_account_id');

        $this->assertSame('revenue', AccountType::Revenue->value);
    }
}
