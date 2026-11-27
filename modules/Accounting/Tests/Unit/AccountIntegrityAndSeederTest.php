<?php

declare(strict_types=1);

namespace Modules\Accounting\Tests\Unit;

use App\Models\Company;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Actions\SeedDefaultAccountsAction;
use Modules\Accounting\Database\Seeders\AccountingDemoSeeder;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\NormalBalance;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Database\Seeders\CoreDemoSeeder;
use Modules\Core\Models\Product;
use Modules\Platform\Database\Seeders\SaasModuleSeeder;
use Modules\Platform\Database\Seeders\TenantDemoSeeder;
use Tests\TestCase;

/**
 * Unit tests verifying COA seeding idempotency, account deletion guards, and AccountingDemoSeeder.
 */
class AccountIntegrityAndSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure SeedDefaultAccountsAction creates all 13 standard system accounts idempotently.
     */
    public function test_seed_default_accounts_action_is_idempotent(): void
    {
        /** Arrange a company and execute SeedDefaultAccountsAction twice. */
        $company = Company::factory()->create();
        $action = app(SeedDefaultAccountsAction::class);

        $firstRun = $action->execute($company);
        $secondRun = $action->execute($company);

        /** Assert exactly 13 system accounts exist with expected codes. */
        $this->assertCount(13, $firstRun);
        $this->assertCount(13, $secondRun);
        $this->assertSame(13, Account::query()->withoutGlobalScope('company')->where('company_id', $company->getKey())->count());

        $codes = Account::query()->withoutGlobalScope('company')->where('company_id', $company->getKey())->pluck('code')->all();
        foreach (['1101', '1102', '1103', '1104', '1201', '2101', '2102', '2103', '3101', '4101', '5101', '6101', '6102'] as $expectedCode) {
            $this->assertContains($expectedCode, $codes);
        }
    }

    /**
     * Ensure system accounts and accounts referenced in journal items cannot be deleted.
     */
    public function test_prevents_deleting_system_accounts_or_used_custom_accounts(): void
    {
        /** Arrange a company with default system accounts and a custom account used in a journal. */
        $company = Company::factory()->create();
        $accounts = app(SeedDefaultAccountsAction::class)->execute($company)->keyBy('code');

        /** @var Account $systemCash */
        $systemCash = $accounts->get('1101');

        $customExpense = Account::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => '6999',
            'name' => 'Beban Lainnya',
            'type' => AccountType::Expense,
            'normal_balance' => NormalBalance::Debit,
            'is_system' => false,
        ]);

        app(JournalPostingService::class)->createAndPostJournal(
            company: $company,
            journalDate: '2026-11-27',
            description: 'Custom expense entry',
            items: [
                ['account_id' => $customExpense->getKey(), 'debit' => '50000.0000'],
                ['account_id' => $systemCash->getKey(), 'credit' => '50000.0000'],
            ],
        );

        /** Assert deleting system account throws DomainException. */
        try {
            $systemCash->delete();
            $this->fail('Expected DomainException when deleting a system account.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('System accounts cannot be deleted', $exception->getMessage());
        }

        /** Assert deleting used custom account throws DomainException. */
        $this->expectException(DomainException::class);
        $customExpense->delete();
    }

    /**
     * Ensure AccountingDemoSeeder populates Toko Maju idempotently without activating accounting on Gym Sehat.
     */
    public function test_accounting_demo_seeder_is_idempotent_and_preserves_gym_sehat_module_gate(): void
    {
        /** Act by running Platform, Core, and Accounting seeders twice consecutively. */
        $seeders = [
            SaasModuleSeeder::class,
            TenantDemoSeeder::class,
            CoreDemoSeeder::class,
            AccountingDemoSeeder::class,
        ];
        $this->seed($seeders);
        $this->seed($seeders);

        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        $tokoProduct = Product::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();

        /** Assert Toko Maju COA, linked product accounts, opening journal, and expense journal exist without duplicates. */
        $this->assertSame(13, Account::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->count());
        $this->assertNotNull($tokoProduct->income_account_id);
        $this->assertNotNull($tokoProduct->cogs_account_id);
        $this->assertNotNull($tokoProduct->inventory_account_id);
        $this->assertSame(2, Journal::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->count());
        $this->assertSame(1, Expense::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->count());

        /** Assert Gym Sehat remains without accounting module activation or seeded journals. */
        $this->assertFalse($gymSehat->hasActiveModule('accounting'));
        $this->assertSame(0, Account::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->count());
        $this->assertSame(0, Journal::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->count());
    }
}
