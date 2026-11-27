<?php

declare(strict_types=1);

namespace Modules\Platform\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Platform\Enums\PaymentGatewayMode;
use Modules\Platform\Filament\Tenant\Pages\ActiveModulesPage;
use Modules\Platform\Filament\Tenant\Pages\CompanySettingsPage;
use Modules\Platform\Filament\Tenant\Pages\WalletOverviewPage;
use Modules\Platform\Filament\Tenant\Resources\TenantBankAccountResource;
use Modules\Platform\Filament\Tenant\Resources\TenantBankAccountResource\Pages\ListTenantBankAccounts;
use Modules\Platform\Models\CompanySetting;
use Modules\Platform\Models\SaasTenantBankAccount;
use Modules\Platform\Models\SaasTenantWallet;
use Modules\Platform\Models\SaasWithdrawal;
use Modules\Platform\Services\WalletLedgerService;
use Tests\TestCase;

/**
 * Feature tests verifying tenant-scoped Platform UI pages, CRUD isolation, and withdrawal validation.
 */
class TenantPlatformPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure seeded tenant owners can render permitted Platform pages and are blocked across tenants.
     */
    public function test_tenant_pages_and_bank_account_resource_enforce_cross_tenant_isolation(): void
    {
        /** Arrange seeded demo tenants (Toko Maju = platform_midtrans, Gym Sehat = own_midtrans). */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $owner2 = User::query()->where('email', 'owner2@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        $gymBankAccount = SaasTenantBankAccount::query()
            ->withoutGlobalScope('company')
            ->create([
                'company_id' => $gymSehat->getKey(),
                'bank_code' => '014',
                'bank_name' => 'BCA',
                'account_number' => '999000111',
                'account_holder_name' => 'Owner Gym Sehat',
            ]);

        /** Act and assert owner1 accesses Toko Maju pages cleanly and cannot access Gym Sehat URLs. */
        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        $this->get(CompanySettingsPage::getUrl(['tenant' => $tokoMaju]))->assertOk();
        $this->get(ActiveModulesPage::getUrl(['tenant' => $tokoMaju]))
            ->assertOk()
            ->assertSee('Point of Sale (POS)')
            ->assertSee('Accounting & Double-Entry');
        $this->get(WalletOverviewPage::getUrl(['tenant' => $tokoMaju]))->assertOk();
        $this->get(TenantBankAccountResource::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(TenantBankAccountResource::getUrl('create', ['tenant' => $tokoMaju]))->assertOk();

        /** Assert owner1 is blocked from accessing Gym Sehat routes or editing Gym Sehat's bank account. */
        $this->get('/app/'.$gymSehat->slug.'/company-settings-page')->assertNotFound();
        $this->get('/app/'.$tokoMaju->slug.'/tenant-bank-accounts/'.$gymBankAccount->getKey().'/edit')->assertNotFound();

        $this->flushSession();

        /** Assert owner2 (own_midtrans with 0 balance) accesses settings/modules/banks, while WalletOverview is 403. */
        $this->actingAs($owner2);
        Filament::setTenant($gymSehat, isQuiet: true);

        $this->get(CompanySettingsPage::getUrl(['tenant' => $gymSehat]))->assertOk();
        $this->get(ActiveModulesPage::getUrl(['tenant' => $gymSehat]))
            ->assertOk()
            ->assertSee('Membership & Quota');
        $this->get(TenantBankAccountResource::getUrl('index', ['tenant' => $gymSehat]))->assertOk();
        $this->get(WalletOverviewPage::getUrl(['tenant' => $gymSehat]))->assertForbidden();
    }

    /**
     * Ensure CompanySettingsPage saves settings strictly for the active tenant without affecting other tenants.
     */
    public function test_company_settings_page_saves_only_active_tenant_settings(): void
    {
        /** Arrange seeded demo tenants. */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Act by updating Toko Maju settings via Livewire page submission. */
        Livewire::test(CompanySettingsPage::class)
            ->fillForm([
                'currency' => 'SGD',
                'tax_rate' => '9.00',
                'timezone' => 'Asia/Makassar',
                'invoice_prefix' => 'MAJU-',
                'fonnte_token' => 'toko-maju-fonnte',
                'payment_gateway_mode' => PaymentGatewayMode::OwnMidtrans->value,
                'midtrans_is_production' => true,
                'midtrans_merchant_id' => 'MAJU-M01',
                'midtrans_server_key' => 'Mid-server-toko-maju',
                'midtrans_client_key' => 'Mid-client-toko-maju',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $tokoSettings = CompanySetting::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $gymSettings = CompanySetting::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->firstOrFail();

        /** Assert Toko Maju updated while Gym Sehat remained completely isolated. */
        $this->assertSame('SGD', $tokoSettings->currency);
        $this->assertSame('MAJU-', $tokoSettings->invoice_prefix);
        $this->assertSame(PaymentGatewayMode::OwnMidtrans, $tokoSettings->payment_gateway_mode);
        $this->assertSame('Mid-server-toko-maju', $tokoSettings->midtrans_server_key);

        $this->assertSame('IDR', $gymSettings->currency);
        $this->assertSame('DEMO-GYM-M01', $gymSettings->midtrans_merchant_id);
    }

    /**
     * Ensure TenantBankAccountResource listing shows only records owned by the active tenant.
     */
    public function test_bank_account_resource_table_displays_only_active_tenant_records(): void
    {
        /** Arrange bank accounts for both Toko Maju and Gym Sehat. */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        $tokoAccount = SaasTenantBankAccount::query()->withoutGlobalScope('company')->create([
            'company_id' => $tokoMaju->getKey(),
            'bank_code' => '014',
            'bank_name' => 'BCA',
            'account_number' => '111000222',
            'account_holder_name' => 'Toko Maju Owner',
        ]);

        $gymAccount = SaasTenantBankAccount::query()->withoutGlobalScope('company')->create([
            'company_id' => $gymSehat->getKey(),
            'bank_code' => '008',
            'bank_name' => 'Mandiri',
            'account_number' => '888000999',
            'account_holder_name' => 'Gym Sehat Owner',
        ]);

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Assert Livewire table lists only Toko Maju's bank account. */
        Livewire::test(ListTenantBankAccounts::class)
            ->assertCanSeeTableRecords([$tokoAccount])
            ->assertCanNotSeeTableRecords([$gymAccount]);
    }

    /**
     * Ensure WalletOverviewPage withdrawal modal succeeds for valid requests and rejects excess or cross-tenant accounts.
     */
    public function test_wallet_overview_page_validates_withdrawal_amount_and_bank_account_ownership(): void
    {
        /** Arrange Toko Maju with 200,000 balance, own bank account, and Gym Sehat's bank account. */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        app(WalletLedgerService::class)->creditSale($tokoMaju, '200000.0000', 'trx_payments', 55, 'Order POS-055');

        $tokoBank = SaasTenantBankAccount::query()->withoutGlobalScope('company')->create([
            'company_id' => $tokoMaju->getKey(),
            'bank_code' => '014',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'Toko Maju',
        ]);

        $gymBank = SaasTenantBankAccount::query()->withoutGlobalScope('company')->create([
            'company_id' => $gymSehat->getKey(),
            'bank_code' => '008',
            'bank_name' => 'Mandiri',
            'account_number' => '9998887776',
            'account_holder_name' => 'Gym Sehat',
        ]);

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Attempt 1: Reject withdrawal exceeding available_balance (250,000 > 200,000). */
        Livewire::test(WalletOverviewPage::class)
            ->callAction('requestWithdrawal', data: [
                'bank_account_id' => $tokoBank->getKey(),
                'amount' => '250000',
                'notes' => 'Overdraft attempt',
            ])
            ->assertActionHalted('requestWithdrawal');

        $this->assertSame(0, SaasWithdrawal::query()->withoutGlobalScope('company')->count());

        /** Attempt 2: Reject withdrawal targeting another tenant's bank account ID. */
        Livewire::test(WalletOverviewPage::class)
            ->callAction('requestWithdrawal', data: [
                'bank_account_id' => $gymBank->getKey(),
                'amount' => '50000',
                'notes' => 'Cross-tenant hijack attempt',
            ]);

        $this->assertSame(0, SaasWithdrawal::query()->withoutGlobalScope('company')->count());

        /** Attempt 3: Valid withdrawal moves 150,000 from available_balance to pending_balance. */
        Livewire::test(WalletOverviewPage::class)
            ->callAction('requestWithdrawal', data: [
                'bank_account_id' => $tokoBank->getKey(),
                'amount' => '150000',
                'notes' => 'Valid payout',
            ])
            ->assertHasNoActionErrors();

        $wallet = SaasTenantWallet::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();

        $this->assertSame('50000.0000', $wallet->available_balance);
        $this->assertSame('150000.0000', $wallet->pending_balance);
        $this->assertSame(1, SaasWithdrawal::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->count());
    }
}
