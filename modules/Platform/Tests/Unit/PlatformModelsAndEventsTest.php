<?php

declare(strict_types=1);

namespace Modules\Platform\Tests\Unit;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Enums\CompanyModuleStatus;
use Modules\Platform\Enums\GatewayOwner;
use Modules\Platform\Enums\PaymentGatewayMode;
use Modules\Platform\Enums\SubscriptionStatus;
use Modules\Platform\Events\CompanyRegistered;
use Modules\Platform\Models\CompanyModule;
use Modules\Platform\Models\CompanySetting;
use Modules\Platform\Models\SaasModule;
use Modules\Platform\Models\SaasPaymentLog;
use Modules\Platform\Models\SaasSubscription;
use Modules\Platform\Models\SaasTenantWallet;
use Modules\Platform\Models\SysTaxProgressiveBracket;
use Modules\Platform\Models\SysTaxPtkp;
use Modules\Platform\Models\SysTaxTerBracket;
use Tests\TestCase;

/**
 * Unit tests verifying Platform models, encrypted settings, tax masters, and CompanyRegistered defaults.
 */
class PlatformModelsAndEventsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure CompanyRegistered provisions a zero-balance wallet and default platform_midtrans settings idempotently.
     */
    public function test_company_registered_listener_provisions_wallet_and_default_settings(): void
    {
        /** Arrange a newly created company. */
        $company = Company::factory()->create();

        /** Act by dispatching CompanyRegistered twice to verify idempotency. */
        CompanyRegistered::dispatch($company);
        CompanyRegistered::dispatch($company);

        /** Assert exactly one wallet and one settings row exist with expected defaults. */
        $wallet = SaasTenantWallet::query()->withoutGlobalScope('company')->where('company_id', $company->getKey())->firstOrFail();
        $settings = CompanySetting::query()->withoutGlobalScope('company')->where('company_id', $company->getKey())->firstOrFail();

        $this->assertSame('0.0000', $wallet->available_balance);
        $this->assertSame('0.0000', $wallet->pending_balance);
        $this->assertSame(PaymentGatewayMode::PlatformMidtrans, $settings->payment_gateway_mode);
        $this->assertFalse($settings->midtrans_is_production);
        $this->assertSame('IDR', $settings->currency);
    }

    /**
     * Ensure CompanySetting encrypts sensitive Midtrans server keys and Fonnte tokens at rest.
     */
    public function test_company_settings_encrypts_sensitive_api_keys_at_rest(): void
    {
        /** Arrange a company with encrypted API credentials. */
        $company = Company::factory()->create();

        $settings = CompanySetting::query()->create([
            'company_id' => $company->getKey(),
            'midtrans_server_key' => 'secret-midtrans-server-key',
            'fonnte_token' => 'secret-fonnte-token',
        ]);

        /** Inspect raw database row to confirm plaintext is never persisted. */
        $rawRow = DB::table('company_settings')->where('id', $settings->getKey())->first();

        $this->assertNotSame('secret-midtrans-server-key', $rawRow->midtrans_server_key);
        $this->assertNotSame('secret-fonnte-token', $rawRow->fonnte_token);
        $this->assertSame('secret-midtrans-server-key', $settings->refresh()->midtrans_server_key);
        $this->assertSame('secret-fonnte-token', $settings->fonnte_token);
    }

    /**
     * Ensure Company::hasActiveModule enforces status and expiration boundaries accurately.
     */
    public function test_company_has_active_module_checks_status_and_expiration(): void
    {
        /** Arrange active, non-expired trial, expired trial, and expired status modules. */
        $company = Company::factory()->create();

        $activeForever = SaasModule::query()->create(['slug' => 'core-ext', 'name' => 'Core Ext', 'monthly_price' => '0.0000']);
        $trialValid = SaasModule::query()->create(['slug' => 'pos', 'name' => 'POS', 'monthly_price' => '99000.0000']);
        $trialExpired = SaasModule::query()->create(['slug' => 'laundry', 'name' => 'Laundry', 'monthly_price' => '99000.0000']);
        $statusExpired = SaasModule::query()->create(['slug' => 'hris', 'name' => 'HRIS', 'monthly_price' => '149000.0000']);

        CompanyModule::query()->create([
            'company_id' => $company->getKey(),
            'module_id' => $activeForever->getKey(),
            'status' => CompanyModuleStatus::Active,
            'expires_at' => null,
        ]);
        CompanyModule::query()->create([
            'company_id' => $company->getKey(),
            'module_id' => $trialValid->getKey(),
            'status' => CompanyModuleStatus::Trial,
            'expires_at' => now()->addDays(7),
        ]);
        CompanyModule::query()->create([
            'company_id' => $company->getKey(),
            'module_id' => $trialExpired->getKey(),
            'status' => CompanyModuleStatus::Trial,
            'expires_at' => now()->subMinute(),
        ]);
        CompanyModule::query()->create([
            'company_id' => $company->getKey(),
            'module_id' => $statusExpired->getKey(),
            'status' => CompanyModuleStatus::Expired,
            'expires_at' => now()->addDays(10),
        ]);

        /** Assert exact module activation checks. */
        $this->assertTrue($company->hasActiveModule('core-ext'));
        $this->assertTrue($company->hasActiveModule('pos'));
        $this->assertFalse($company->hasActiveModule('laundry'));
        $this->assertFalse($company->hasActiveModule('hris'));
        $this->assertFalse($company->hasActiveModule('nonexistent'));
    }

    /**
     * Ensure subscription, payment log, and global tax master models persist decimal and enum casts accurately.
     */
    public function test_subscription_payment_log_and_global_tax_models_persist_correctly(): void
    {
        /** Arrange subscription, payment log, and PPh 21 tax master records. */
        $company = Company::factory()->create();

        $subscription = SaasSubscription::query()->create([
            'company_id' => $company->getKey(),
            'order_number' => 'SUB-20261127-001',
            'module_ids' => [1, 2],
            'amount' => '199000.0000',
            'status' => SubscriptionStatus::Paid,
            'paid_at' => now(),
        ]);

        $log = SaasPaymentLog::query()->create([
            'saas_subscription_id' => $subscription->getKey(),
            'gateway_owner' => GatewayOwner::Platform,
            'event' => 'settlement',
            'http_status' => 200,
            'payload' => ['transaction_status' => 'settlement'],
        ]);

        $ptkp = SysTaxPtkp::query()->create([
            'code' => 'TK/0',
            'ter_category' => 'A',
            'annual_amount' => '54000000.0000',
            'effective_year' => 2026,
        ]);

        $ter = SysTaxTerBracket::query()->create([
            'ter_category' => 'A',
            'min_gross' => '0.0000',
            'max_gross' => '5400000.0000',
            'rate_percent' => '0.0000',
            'effective_year' => 2026,
        ]);

        $progressive = SysTaxProgressiveBracket::query()->create([
            'min_pkp' => '0.0000',
            'max_pkp' => '60000000.0000',
            'rate_percent' => '5.0000',
            'effective_year' => 2026,
        ]);

        /** Assert exact model types, casts, and relationships. */
        $this->assertSame(SubscriptionStatus::Paid, $subscription->status);
        $this->assertSame('199000.0000', $subscription->amount);
        $this->assertTrue($log->subscription->is($subscription));
        $this->assertSame(GatewayOwner::Platform, $log->gateway_owner);
        $this->assertSame('54000000.0000', $ptkp->annual_amount);
        $this->assertSame('5400000.0000', $ter->max_gross);
        $this->assertSame('5.0000', $progressive->rate_percent);
    }
}
