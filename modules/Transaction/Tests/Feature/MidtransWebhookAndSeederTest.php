<?php

declare(strict_types=1);

namespace Modules\Transaction\Tests\Feature;

use App\Models\Company;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Services\FinancialReportService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\InventoryStock;
use Modules\Core\Models\ProductVariant;
use Modules\Platform\Database\Seeders\SaasModuleSeeder;
use Modules\Platform\Database\Seeders\TenantDemoSeeder;
use Modules\Core\Database\Seeders\CoreDemoSeeder;
use Modules\Transaction\Enums\OrderChannel;
use Modules\Transaction\Enums\PaymentStatus;
use Modules\Transaction\Models\Order;
use Modules\Transaction\Models\PosShift;
use Modules\Transaction\Models\PosTable;
use Modules\Transaction\Services\OrderCreationService;
use Tests\TestCase;

/**
 * Feature tests verifying HTTP Midtrans webhook endpoints and idempotent TransactionDemoSeeder integration.
 */
class MidtransWebhookAndSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure Platform and Tenant Midtrans HTTP webhook routes validate signatures and settle orders idempotently.
     */
    public function test_http_platform_and_tenant_midtrans_webhook_routes(): void
    {
        /** Arrange Toko Maju (platform_midtrans) and Gym Sehat (own_midtrans). */
        $this->seed([
            SaasModuleSeeder::class,
            TenantDemoSeeder::class,
            CoreDemoSeeder::class,
        ]);

        $platformKey = 'SB-Mid-server-http-platform';
        config([
            'services.midtrans.server_key' => $platformKey,
            'services.midtrans.client_key' => 'SB-Mid-client-http-platform',
        ]);

        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $tokoBranch = Branch::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $tokoVariant = ProductVariant::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->where('sku', 'KOPI-ARB-250')->firstOrFail();

        $tokoOrder = app(OrderCreationService::class)->createOrder(
            company: $tokoMaju,
            branch: $tokoBranch,
            channel: OrderChannel::Web,
            items: [['product_variant_id' => $tokoVariant->getKey(), 'qty' => '1.00']],
        );

        /** Reject forged signature with 403. */
        $this->postJson('/api/webhooks/midtrans/platform', [
            'order_id' => $tokoOrder->order_number,
            'status_code' => '200',
            'gross_amount' => '75000.00',
            'transaction_status' => 'settlement',
            'signature_key' => 'invalid-signature',
        ])->assertForbidden();

        /** Accept valid Platform webhook with 200. */
        $this->postJson('/api/webhooks/midtrans/platform', [
            'order_id' => $tokoOrder->order_number,
            'status_code' => '200',
            'gross_amount' => '75000.00',
            'transaction_status' => 'settlement',
            'payment_type' => 'qris',
            'transaction_id' => 'HTTP-PLAT-01',
            'signature_key' => hash('sha512', $tokoOrder->order_number.'200'.'75000.00'.$platformKey),
        ])->assertOk()->assertJsonPath('payment_status', 'paid');

        $this->assertSame(PaymentStatus::Paid, $tokoOrder->refresh()->payment_status);

        /** Test tenant-scoped webhook route for Gym Sehat. */
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();
        $gymBranch = Branch::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->firstOrFail();
        $gymVariant = ProductVariant::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->where('sku', 'GYM-MONTHLY-01')->firstOrFail();

        $gymOrder = app(OrderCreationService::class)->createOrder(
            company: $gymSehat,
            branch: $gymBranch,
            channel: OrderChannel::Membership,
            items: [['product_variant_id' => $gymVariant->getKey(), 'qty' => '1.00']],
        );

        $tenantKey = 'SB-Mid-server-demo-gym-sehat';
        $this->postJson('/api/webhooks/midtrans/tenant/gym-sehat', [
            'order_id' => $gymOrder->order_number,
            'status_code' => '200',
            'gross_amount' => '350000.00',
            'transaction_status' => 'settlement',
            'payment_type' => 'bank_transfer',
            'transaction_id' => 'HTTP-TENANT-01',
            'signature_key' => hash('sha512', $gymOrder->order_number.'200'.'350000.00'.$tenantKey),
        ])->assertOk()->assertJsonPath('payment_status', 'paid');

        $this->assertSame(PaymentStatus::Paid, $gymOrder->refresh()->payment_status);
    }

    /**
     * Ensure DatabaseSeeder runs TransactionDemoSeeder idempotently, deducts stock, and posts revenue+COGS into reports.
     */
    public function test_transaction_demo_seeder_is_idempotent_and_visible_in_stock_and_financial_reports(): void
    {
        /** Act by executing full DatabaseSeeder twice consecutively. */
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();

        /** Assert exact non-duplicated Transaction demo rows. */
        $this->assertSame(1, PosTable::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->count());
        $this->assertSame(1, PosShift::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->count());
        $this->assertSame(1, Order::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->count());

        $order = Order::query()->withoutGlobalScope('company')->where('order_number', 'TRX-SEED-001')->firstOrFail();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('150000.0000', $order->grand_total);

        /** Assert stock reduced from 50.00 to 48.00 and Accounting has 3 journals (capital, expense, sales+COGS). */
        $stock = InventoryStock::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $this->assertSame('48.00', $stock->quantity);
        $this->assertSame(3, Journal::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->count());

        /** Assert Profit & Loss reflects 150,000 revenue - (500,000 expense + 90,000 COGS) = -440,000 net profit. */
        $pnl = app(FinancialReportService::class)->getProfitAndLoss(
            company: $tokoMaju,
            startDate: now()->startOfMonth(),
            endDate: now()->endOfMonth(),
        );
        $this->assertSame('150000.0000', $pnl->totalRevenue);
        $this->assertSame('590000.0000', $pnl->totalExpense);
        $this->assertSame('-440000.0000', $pnl->netProfit);
    }
}
