<?php

declare(strict_types=1);

namespace Modules\Platform\Tests\Unit;

use App\Models\Company;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Platform\Actions\ActivateCompanyModulesAction;
use Modules\Platform\Actions\UpdateCompanySettingsAction;
use Modules\Platform\Enums\CompanyModuleStatus;
use Modules\Platform\Enums\PaymentGatewayMode;
use Modules\Platform\Filament\Tenant\Pages\WalletOverviewPage;
use Modules\Platform\Models\CompanySetting;
use Modules\Platform\Models\SaasModule;
use Modules\Platform\Services\TenantModuleCatalogService;
use Modules\Platform\Services\WalletLedgerService;
use Tests\TestCase;

/**
 * Unit tests verifying tenant settings updates, module catalog formatting, and wallet page visibility.
 */
class TenantPlatformActionsAndCatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Clear active Filament tenant after each test.
     */
    protected function tearDown(): void
    {
        Filament::setTenant(null);

        parent::tearDown();
    }

    /**
     * Ensure UpdateCompanySettingsAction updates only the target company and encrypts secrets at rest.
     */
    public function test_update_company_settings_encrypts_secrets_and_isolates_tenants(): void
    {
        /** Arrange two companies while Filament tenant is pointed at Company B. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        CompanySetting::query()->create([
            'company_id' => $companyB->getKey(),
            'currency' => 'USD',
            'invoice_prefix' => 'COMPB-',
        ]);

        Filament::setTenant($companyB, isQuiet: true);

        /** Act by updating Company A to own_midtrans mode with encrypted credentials. */
        $updated = app(UpdateCompanySettingsAction::class)->execute($companyA, [
            'currency' => 'idr',
            'tax_rate' => '11',
            'timezone' => 'Asia/Jakarta',
            'invoice_prefix' => 'TM-',
            'fonnte_token' => 'fonnte-secret-token',
            'payment_gateway_mode' => PaymentGatewayMode::OwnMidtrans->value,
            'midtrans_is_production' => true,
            'midtrans_merchant_id' => 'M-123',
            'midtrans_server_key' => 'Mid-server-secret',
            'midtrans_client_key' => 'Mid-client-public',
        ]);

        $rawRow = DB::table('company_settings')->where('id', $updated->getKey())->first();
        $companyBSettings = CompanySetting::query()->withoutGlobalScope('company')->where('company_id', $companyB->getKey())->firstOrFail();

        /** Assert Company A settings are encrypted and Company B remains untouched. */
        $this->assertSame('IDR', $updated->currency);
        $this->assertSame('11.00', $updated->tax_rate);
        $this->assertSame(PaymentGatewayMode::OwnMidtrans, $updated->payment_gateway_mode);
        $this->assertNotSame('Mid-server-secret', $rawRow->midtrans_server_key);
        $this->assertNotSame('fonnte-secret-token', $rawRow->fonnte_token);
        $this->assertSame('Mid-server-secret', $updated->midtrans_server_key);
        $this->assertSame('fonnte-secret-token', $updated->fonnte_token);
        $this->assertSame('USD', $companyBSettings->currency);
        $this->assertSame('COMPB-', $companyBSettings->invoice_prefix);
    }

    /**
     * Ensure UpdateCompanySettingsAction rejects own_midtrans when Midtrans keys are blank.
     */
    public function test_update_company_settings_rejects_incomplete_own_midtrans_credentials(): void
    {
        /** Arrange a company. */
        $company = Company::factory()->create();

        /** Expect InvalidArgumentException when own_midtrans lacks server/client keys. */
        $this->expectException(InvalidArgumentException::class);

        app(UpdateCompanySettingsAction::class)->execute($company, [
            'payment_gateway_mode' => PaymentGatewayMode::OwnMidtrans->value,
            'midtrans_server_key' => '',
            'midtrans_client_key' => '',
        ]);
    }

    /**
     * Ensure TenantModuleCatalogService separates owned entitlements from unowned add-ons per tenant.
     */
    public function test_tenant_module_catalog_service_formats_owned_and_available_addons(): void
    {
        /** Arrange modules and assign only POS (active) and Laundry (expired) to Company A. */
        $pos = SaasModule::query()->create(['slug' => 'pos', 'name' => 'Point of Sale', 'monthly_price' => '99000.0000']);
        $laundry = SaasModule::query()->create(['slug' => 'laundry', 'name' => 'Laundry', 'monthly_price' => '99000.0000']);
        $qrOrder = SaasModule::query()->create([
            'slug' => 'qr_order',
            'name' => 'QR Order',
            'monthly_price' => '59000.0000',
            'required_modules' => ['pos'],
        ]);

        $company = Company::factory()->create();
        $activate = app(ActivateCompanyModulesAction::class);

        $activate->execute($company, [$pos->getKey()], expiresAt: now()->addMonth(), status: CompanyModuleStatus::Active);
        $activate->execute($company, [$laundry->getKey()], expiresAt: now()->subDay(), status: CompanyModuleStatus::Trial);

        /** Act by fetching the catalog for the company. */
        $catalog = app(TenantModuleCatalogService::class)->getCatalogForCompany($company);

        /** Assert 2 owned entries (Active + Expired) and 1 unowned add-on (QR Order). */
        $this->assertCount(2, $catalog['owned']);
        $this->assertCount(1, $catalog['available_addons']);
        $this->assertSame('qr_order', $catalog['available_addons'][0]['slug']);
        $this->assertSame('Requires: Point of Sale', $catalog['available_addons'][0]['required_modules_text']);

        $ownedBySlug = collect($catalog['owned'])->keyBy('slug');
        $this->assertSame('Active', $ownedBySlug['pos']['status_label']);
        $this->assertSame('success', $ownedBySlug['pos']['status_color']);
        $this->assertSame('Expired', $ownedBySlug['laundry']['status_label']);
        $this->assertSame('danger', $ownedBySlug['laundry']['status_color']);
        $this->assertNotNull($qrOrder->getKey());
    }

    /**
     * Ensure WalletOverviewPage::canAccess enforces platform_midtrans or positive wallet balance rules.
     */
    public function test_wallet_overview_page_can_access_depends_on_gateway_mode_and_balance(): void
    {
        /** Assert no tenant denies access. */
        Filament::setTenant(null);
        $this->assertFalse(WalletOverviewPage::canAccess());

        /** Arrange platform_midtrans tenant with zero balance -> allowed. */
        $platformTenant = Company::factory()->create();
        CompanySetting::query()->create([
            'company_id' => $platformTenant->getKey(),
            'payment_gateway_mode' => PaymentGatewayMode::PlatformMidtrans,
        ]);

        Filament::setTenant($platformTenant, isQuiet: true);
        $this->assertTrue(WalletOverviewPage::canAccess());

        /** Arrange own_midtrans tenant with zero balance -> denied, then funded -> allowed. */
        $ownTenant = Company::factory()->create();
        CompanySetting::query()->create([
            'company_id' => $ownTenant->getKey(),
            'payment_gateway_mode' => PaymentGatewayMode::OwnMidtrans,
            'midtrans_server_key' => 'srv',
            'midtrans_client_key' => 'cli',
        ]);

        Filament::setTenant($ownTenant, isQuiet: true);
        $this->assertFalse(WalletOverviewPage::canAccess());

        app(WalletLedgerService::class)->creditSale($ownTenant, '50000.0000', 'trx_payments', 1);
        $this->assertTrue(WalletOverviewPage::canAccess());
    }
}
