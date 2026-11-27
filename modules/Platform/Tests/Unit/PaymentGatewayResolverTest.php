<?php

declare(strict_types=1);

namespace Modules\Platform\Tests\Unit;

use App\Models\Company;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Platform\Enums\GatewayOwner;
use Modules\Platform\Enums\PaymentGatewayMode;
use Modules\Platform\Models\CompanySetting;
use Modules\Platform\Services\PaymentGatewayResolver;
use RuntimeException;
use Tests\TestCase;

/**
 * Unit tests verifying dual-mode Midtrans credential resolution in PaymentGatewayResolver.
 */
class PaymentGatewayResolverTest extends TestCase
{
    use RefreshDatabase;

    private PaymentGatewayResolver $resolver;

    /**
     * Set up default Platform Midtrans configuration and resolver instance.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.midtrans', [
            'is_production' => false,
            'merchant_id' => 'PLAT-M-001',
            'server_key' => 'SB-Mid-server-platform-secret',
            'client_key' => 'SB-Mid-client-platform-public',
        ]);

        $this->resolver = app(PaymentGatewayResolver::class);
    }

    /**
     * Clear any active Filament tenant after each test.
     */
    protected function tearDown(): void
    {
        Filament::setTenant(null);

        parent::tearDown();
    }

    /**
     * Ensure companies without settings or configured for platform_midtrans resolve Platform keys.
     */
    public function test_resolves_platform_credentials_by_default(): void
    {
        /** Arrange a company with no custom settings row yet. */
        $company = Company::factory()->create();

        /** Act by resolving Midtrans credentials for the company. */
        $credentials = $this->resolver->resolveForCompany($company);

        /** Assert Platform Midtrans credentials are returned. */
        $this->assertSame(GatewayOwner::Platform, $credentials->gatewayOwner);
        $this->assertFalse($credentials->isProduction);
        $this->assertSame('PLAT-M-001', $credentials->merchantId);
        $this->assertSame('SB-Mid-server-platform-secret', $credentials->serverKey);
        $this->assertSame('SB-Mid-client-platform-public', $credentials->clientKey);
    }

    /**
     * Ensure companies configured for own_midtrans resolve decrypted tenant credentials regardless of active Filament tenant.
     */
    public function test_resolves_tenant_credentials_when_own_midtrans_is_enabled(): void
    {
        /** Arrange a target company with own_midtrans and an unrelated active Filament tenant. */
        $targetCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();

        CompanySetting::query()->create([
            'company_id' => $targetCompany->getKey(),
            'payment_gateway_mode' => PaymentGatewayMode::OwnMidtrans,
            'midtrans_is_production' => true,
            'midtrans_merchant_id' => 'TENANT-M-999',
            'midtrans_server_key' => 'Mid-server-tenant-secret',
            'midtrans_client_key' => 'Mid-client-tenant-public',
        ]);

        Filament::setTenant($otherCompany, isQuiet: true);

        /** Act by resolving credentials for the target company and for platform subscription. */
        $tenantCredentials = $this->resolver->resolveForCompany($targetCompany);
        $subscriptionCredentials = $this->resolver->resolveForPlatformSubscription();

        /** Assert customer payments use tenant keys while SaaS subscriptions still use Platform keys. */
        $this->assertSame(GatewayOwner::Tenant, $tenantCredentials->gatewayOwner);
        $this->assertTrue($tenantCredentials->isProduction);
        $this->assertSame('TENANT-M-999', $tenantCredentials->merchantId);
        $this->assertSame('Mid-server-tenant-secret', $tenantCredentials->serverKey);
        $this->assertSame('Mid-client-tenant-public', $tenantCredentials->clientKey);

        $this->assertSame(GatewayOwner::Platform, $subscriptionCredentials->gatewayOwner);
        $this->assertSame('SB-Mid-server-platform-secret', $subscriptionCredentials->serverKey);
    }

    /**
     * Ensure own_midtrans without complete credentials fails loudly with a RuntimeException.
     */
    public function test_throws_exception_when_own_midtrans_keys_are_missing(): void
    {
        /** Arrange a company set to own_midtrans without server/client keys. */
        $company = Company::factory()->create();

        CompanySetting::query()->create([
            'company_id' => $company->getKey(),
            'payment_gateway_mode' => PaymentGatewayMode::OwnMidtrans,
            'midtrans_server_key' => null,
            'midtrans_client_key' => '',
        ]);

        /** Expect a RuntimeException rejecting incomplete tenant gateway configuration. */
        $this->expectException(RuntimeException::class);

        $this->resolver->resolveForCompany($company);
    }

    /**
     * Ensure missing Platform Midtrans configuration throws a RuntimeException.
     */
    public function test_throws_exception_when_platform_midtrans_config_is_missing(): void
    {
        /** Arrange empty Platform Midtrans server key configuration. */
        config()->set('services.midtrans.server_key', '');

        /** Expect a RuntimeException preventing silent misconfiguration. */
        $this->expectException(RuntimeException::class);

        $this->resolver->resolveForPlatformSubscription();
    }
}
