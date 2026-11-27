<?php

declare(strict_types=1);

namespace Modules\Transaction\Tests\Unit;

use App\Models\Company;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Enums\CategoryType;
use Modules\Core\Enums\ProductType;
use Modules\Core\Enums\ProductUnit;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Category;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductVariant;
use Modules\Platform\Enums\PaymentGatewayMode as CompanyGatewayMode;
use Modules\Platform\Models\CompanySetting;
use Modules\Transaction\Enums\OrderChannel;
use Modules\Transaction\Enums\PaymentGatewayMode;
use Modules\Transaction\Enums\PaymentMethod;
use Modules\Transaction\Enums\PaymentStatus;
use Modules\Transaction\Enums\PaymentTransactionStatus;
use Modules\Transaction\Events\OrderPaid;
use Modules\Transaction\Exceptions\InvalidMidtransSignatureException;
use Modules\Transaction\Models\OrderLog;
use Modules\Transaction\Models\Payment;
use Modules\Transaction\Services\OrderCreationService;
use Modules\Transaction\Services\OrderPaymentService;
use Tests\TestCase;

/**
 * Unit tests verifying manual partial/full settlement, dual-mode Midtrans webhooks, signatures, and idempotency.
 */
class OrderPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure partial manual payments accumulate via bcmath and dispatch OrderPaid once upon full payment.
     */
    public function test_records_partial_manual_payments_and_dispatches_order_paid_once_when_settled(): void
    {
        /** Arrange an order totaling 150,000.0000 with OrderPaid event faked. */
        Event::fake([OrderPaid::class]);

        $company = Company::factory()->create();
        $order = $this->createSampleOrder($company, '150000.0000');
        $paymentService = app(OrderPaymentService::class);

        /** Act 1: Pay 50,000.0000 -> partial, no OrderPaid dispatched yet. */
        $payment1 = $paymentService->recordManualPayment($order, PaymentMethod::Cash, '50000.0000');

        $this->assertSame(PaymentTransactionStatus::Paid, $payment1->status);
        $this->assertSame(PaymentStatus::Partial, $order->refresh()->payment_status);
        Event::assertNotDispatched(OrderPaid::class);

        /** Attempt overpayment beyond remaining 100,000.0000 -> rejected. */
        try {
            $paymentService->recordManualPayment($order, PaymentMethod::Cash, '120000.0000');
            $this->fail('Expected DomainException on overpayment.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('exceeds the remaining', $exception->getMessage());
        }

        /** Act 2: Pay remaining 100,000.0000 -> paid, OrderPaid dispatched once. */
        $paymentService->recordManualPayment($order, PaymentMethod::Transfer, '100000.0000');

        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
        Event::assertDispatchedTimes(OrderPaid::class, 1);
    }

    /**
     * Ensure Platform Midtrans webhook verifies SHA-512, logs raw payload, computes net_amount, and is idempotent.
     */
    public function test_verifies_platform_midtrans_webhook_logs_payload_and_enforces_idempotency(): void
    {
        /** Arrange a Platform-mode tenant order and configured Platform Midtrans credentials. */
        Event::fake([OrderPaid::class]);

        $serverKey = 'SB-Mid-server-platform-secret';
        config([
            'services.midtrans.server_key' => $serverKey,
            'services.midtrans.client_key' => 'SB-Mid-client-platform-key',
            'services.midtrans.is_production' => false,
        ]);

        $company = Company::factory()->create();
        CompanySetting::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'payment_gateway_mode' => CompanyGatewayMode::PlatformMidtrans,
        ]);
        $order = $this->createSampleOrder($company, '150000.0000');

        $payload = [
            'order_id' => $order->order_number,
            'status_code' => '200',
            'gross_amount' => '150000.00',
            'transaction_status' => 'settlement',
            'payment_type' => 'qris',
            'transaction_id' => 'MID-TRX-001',
            'gateway_fee_amount' => '1050.0000',
            'platform_fee_amount' => '1500.0000',
            'signature_key' => hash('sha512', $order->order_number.'200'.'150000.00'.$serverKey),
        ];

        $paymentService = app(OrderPaymentService::class);

        /** Act by delivering the same settlement webhook twice. */
        $firstPayment = $paymentService->handleMidtransWebhook($payload);
        $secondPayment = $paymentService->handleMidtransWebhook($payload);

        /** Assert single paid record with exact net_amount and single OrderPaid dispatch. */
        $this->assertSame($firstPayment->getKey(), $secondPayment->getKey());
        $this->assertSame(PaymentGatewayMode::PlatformMidtrans, $firstPayment->gateway_mode);
        $this->assertSame(PaymentMethod::Qris, $firstPayment->payment_method);
        $this->assertSame('150000.0000', $firstPayment->amount);
        $this->assertSame('1050.0000', $firstPayment->gateway_fee_amount);
        $this->assertSame('1500.0000', $firstPayment->platform_fee_amount);
        $this->assertSame('147450.0000', $firstPayment->net_amount);
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);

        $this->assertSame(1, Payment::query()->withoutGlobalScope('company')->where('order_id', $order->getKey())->count());
        $this->assertSame(2, OrderLog::query()->withoutGlobalScope('company')->where('order_id', $order->getKey())->where('event', 'midtrans_webhook_received')->count());
        Event::assertDispatchedTimes(OrderPaid::class, 1);
    }

    /**
     * Ensure OwnMidtrans webhook verifies against the tenant's encrypted server key.
     */
    public function test_verifies_own_midtrans_webhook_using_tenant_server_key(): void
    {
        /** Arrange an OwnMidtrans tenant and order. */
        Event::fake([OrderPaid::class]);

        $tenantServerKey = 'SB-Mid-server-tenant-unique';
        $company = Company::factory()->create();
        CompanySetting::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'payment_gateway_mode' => CompanyGatewayMode::OwnMidtrans,
            'midtrans_is_production' => false,
            'midtrans_merchant_id' => 'M-TENANT-01',
            'midtrans_server_key' => $tenantServerKey,
            'midtrans_client_key' => 'SB-Mid-client-tenant-unique',
        ]);
        $order = $this->createSampleOrder($company, '200000.0000');

        $payload = [
            'order_id' => $order->order_number,
            'status_code' => '200',
            'gross_amount' => '200000.00',
            'transaction_status' => 'capture',
            'fraud_status' => 'accept',
            'payment_type' => 'bank_transfer',
            'transaction_id' => 'MID-OWN-99',
            'signature_key' => hash('sha512', $order->order_number.'200'.'200000.00'.$tenantServerKey),
        ];

        $payment = app(OrderPaymentService::class)->handleMidtransWebhook($payload, $company);

        $this->assertSame(PaymentGatewayMode::OwnMidtrans, $payment->gateway_mode);
        $this->assertSame(PaymentMethod::Va, $payment->payment_method);
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
        Event::assertDispatchedTimes(OrderPaid::class, 1);
    }

    /**
     * Ensure forged signatures and mismatched gateway route modes are rejected before mutating data.
     */
    public function test_rejects_forged_signature_and_mismatched_gateway_mode(): void
    {
        /** Arrange Platform-mode order and test keys. */
        config([
            'services.midtrans.server_key' => 'SB-Mid-server-real',
            'services.midtrans.client_key' => 'SB-Mid-client-real',
        ]);

        $company = Company::factory()->create();
        CompanySetting::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'payment_gateway_mode' => CompanyGatewayMode::PlatformMidtrans,
        ]);
        $order = $this->createSampleOrder($company, '100000.0000');

        $payload = [
            'order_id' => $order->order_number,
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'transaction_status' => 'settlement',
            'signature_key' => str_repeat('a', 128),
        ];

        $service = app(OrderPaymentService::class);

        try {
            $service->handleMidtransWebhook($payload);
            $this->fail('Expected InvalidMidtransSignatureException for forged signature.');
        } catch (InvalidMidtransSignatureException $exception) {
            $this->assertStringContainsString('Invalid Midtrans webhook signature', $exception->getMessage());
        }

        /** Reject Platform-mode order sent to tenant-scoped endpoint even with valid platform key. */
        $payload['signature_key'] = hash('sha512', $order->order_number.'200'.'100000.00'.'SB-Mid-server-real');

        $this->expectException(InvalidMidtransSignatureException::class);
        $service->handleMidtransWebhook($payload, $company);
    }

    /**
     * Helper to create a single-item order with a fixed grand_total.
     */
    private function createSampleOrder(Company $company, string $unitPrice): \Modules\Transaction\Models\Order
    {
        $branch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'MAIN',
            'name' => 'Main Branch',
            'is_main' => true,
        ]);
        $category = Category::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'name' => 'General',
            'type' => CategoryType::Product,
        ]);
        $product = Product::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'category_id' => $category->getKey(),
            'name' => 'Sample Product',
            'slug' => 'sample-product-'.$company->getKey(),
            'type' => ProductType::Goods,
            'track_stock' => false,
        ]);
        $variant = ProductVariant::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'name' => 'Default',
            'sku' => 'SMP-'.$company->getKey(),
            'unit' => ProductUnit::Pcs,
            'cost_price' => '10000.0000',
            'selling_price' => $unitPrice,
            'is_active' => true,
        ]);

        return app(OrderCreationService::class)->createOrder(
            company: $company,
            branch: $branch,
            channel: OrderChannel::Pos,
            items: [['product_variant_id' => $variant->getKey(), 'qty' => '1.00']],
        );
    }
}
