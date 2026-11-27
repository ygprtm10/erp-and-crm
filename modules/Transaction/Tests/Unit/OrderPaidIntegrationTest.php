<?php

declare(strict_types=1);

namespace Modules\Transaction\Tests\Unit;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Database\Seeders\AccountingDemoSeeder;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Core\Database\Seeders\CoreDemoSeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\InventoryStock;
use Modules\Core\Models\ProductVariant;
use Modules\Core\Models\StockMovement;
use Modules\Platform\Database\Seeders\SaasModuleSeeder;
use Modules\Platform\Database\Seeders\TenantDemoSeeder;
use Modules\Platform\Enums\WalletTransactionType;
use Modules\Platform\Models\SaasTenantWallet;
use Modules\Platform\Models\SaasWalletTransaction;
use Modules\Transaction\Enums\OrderChannel;
use Modules\Transaction\Enums\PaymentStatus;
use Modules\Transaction\Events\OrderPaid;
use Modules\Transaction\Models\Order;
use Modules\Transaction\Models\Payment;
use Modules\Transaction\Services\OrderCreationService;
use Modules\Transaction\Services\OrderPaymentService;
use Tests\TestCase;

/**
 * End-to-end integration tests verifying OrderPaid cross-module listeners across Core, Platform, and Accounting.
 */
class OrderPaidIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure a paid Platform Midtrans order on Toko Maju deducts stock, credits escrow wallet, and posts balanced revenue+COGS journal.
     */
    public function test_paid_order_on_toko_maju_triggers_stock_wallet_and_balanced_accounting_journal_idempotently(): void
    {
        /** Arrange Toko Maju (pos + accounting active, platform_midtrans mode, 50.00 initial stock). */
        $this->seed([
            SaasModuleSeeder::class,
            TenantDemoSeeder::class,
            CoreDemoSeeder::class,
            AccountingDemoSeeder::class,
        ]);

        $platformServerKey = 'SB-Mid-server-platform-integration';
        config([
            'services.midtrans.server_key' => $platformServerKey,
            'services.midtrans.client_key' => 'SB-Mid-client-platform-integration',
        ]);

        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $branch = Branch::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $variant = ProductVariant::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->where('sku', 'KOPI-ARB-250')->firstOrFail();

        $order = app(OrderCreationService::class)->createOrder(
            company: $tokoMaju,
            branch: $branch,
            channel: OrderChannel::Pos,
            items: [['product_variant_id' => $variant->getKey(), 'qty' => '2.00']],
        );

        /** Act by settling 150,000.00 via Platform Midtrans with 3,000 gateway fee + 2,000 platform fee. */
        $payment = app(OrderPaymentService::class)->handleMidtransWebhook([
            'order_id' => $order->order_number,
            'status_code' => '200',
            'gross_amount' => '150000.00',
            'transaction_status' => 'settlement',
            'payment_type' => 'qris',
            'transaction_id' => 'MID-INT-001',
            'gateway_fee_amount' => '3000.0000',
            'platform_fee_amount' => '2000.0000',
            'signature_key' => hash('sha512', $order->order_number.'200'.'150000.00'.$platformServerKey),
        ]);

        /** Assert 1: Core inventory stock reduced from 50.00 to 48.00 with reference to Order. */
        $stock = InventoryStock::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('branch_id', $branch->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->firstOrFail();

        $this->assertSame('48.00', $stock->quantity);
        $this->assertSame(
            1,
            StockMovement::query()
                ->withoutGlobalScope('company')
                ->where('reference_type', Order::class)
                ->where('reference_id', $order->getKey())
                ->count(),
        );

        /** Assert 2: Platform tenant wallet credited with net_amount (145,000.0000). */
        $wallet = SaasTenantWallet::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->firstOrFail();

        $this->assertSame('145000.0000', $wallet->available_balance);
        $this->assertSame('145000.0000', $wallet->total_earned);
        $this->assertSame(
            1,
            SaasWalletTransaction::query()
                ->withoutGlobalScope('company')
                ->where('type', WalletTransactionType::CreditSale->value)
                ->where('reference_type', Payment::class)
                ->where('reference_id', $payment->getKey())
                ->count(),
        );

        /** Assert 3: Accounting posted a balanced Revenue + Gateway Fee + COGS + Inventory journal. */
        $journal = Journal::query()
            ->withoutGlobalScope('company')
            ->with('items')
            ->where('company_id', $tokoMaju->getKey())
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->getKey())
            ->firstOrFail();

        $this->assertSame(JournalStatus::Posted, $journal->status);

        $accountsByCode = Account::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->get()
            ->keyBy('code');

        $itemsByAccount = $journal->items->keyBy('account_id');

        $this->assertSame('145000.0000', $itemsByAccount->get($accountsByCode->get('1103')->getKey())->debit);
        $this->assertSame('5000.0000', $itemsByAccount->get($accountsByCode->get('6102')->getKey())->debit);
        $this->assertSame('150000.0000', $itemsByAccount->get($accountsByCode->get('4101')->getKey())->credit);
        $this->assertSame('90000.0000', $itemsByAccount->get($accountsByCode->get('5101')->getKey())->debit);
        $this->assertSame('90000.0000', $itemsByAccount->get($accountsByCode->get('1201')->getKey())->credit);

        /** Assert 4: Re-dispatching OrderPaid manually is 100% idempotent across all 3 modules. */
        OrderPaid::dispatch($order->refresh(), $payment);

        $this->assertSame('48.00', $stock->refresh()->quantity);
        $this->assertSame('145000.0000', $wallet->refresh()->available_balance);
        $this->assertSame(
            1,
            Journal::query()
                ->withoutGlobalScope('company')
                ->where('reference_type', Order::class)
                ->where('reference_id', $order->getKey())
                ->count(),
        );
    }

    /**
     * Ensure Gym Sehat (own_midtrans, no accounting module, track_stock=false) skips journals and escrow credits without errors.
     */
    public function test_paid_order_on_gym_sehat_without_accounting_module_skips_journals_and_wallet_credit(): void
    {
        /** Arrange Gym Sehat (own_midtrans mode, membership active, accounting inactive). */
        $this->seed([
            SaasModuleSeeder::class,
            TenantDemoSeeder::class,
            CoreDemoSeeder::class,
            AccountingDemoSeeder::class,
        ]);

        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();
        $branch = Branch::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->firstOrFail();
        $variant = ProductVariant::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->where('sku', 'GYM-MONTHLY-01')->firstOrFail();

        $this->assertFalse($gymSehat->hasActiveModule('accounting'));

        $order = app(OrderCreationService::class)->createOrder(
            company: $gymSehat,
            branch: $branch,
            channel: OrderChannel::Membership,
            items: [['product_variant_id' => $variant->getKey(), 'qty' => '1.00']],
        );

        /** Act by settling via Gym Sehat's own Midtrans server key. */
        $tenantServerKey = 'SB-Mid-server-demo-gym-sehat';
        app(OrderPaymentService::class)->handleMidtransWebhook([
            'order_id' => $order->order_number,
            'status_code' => '200',
            'gross_amount' => '350000.00',
            'transaction_status' => 'settlement',
            'payment_type' => 'qris',
            'transaction_id' => 'MID-GYM-001',
            'signature_key' => hash('sha512', $order->order_number.'200'.'350000.00'.$tenantServerKey),
        ], $gymSehat);

        /** Assert order settled cleanly with zero journals, zero stock movements, and zero escrow wallet credit. */
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);

        $wallet = SaasTenantWallet::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->firstOrFail();
        $this->assertSame('0.0000', $wallet->available_balance);
        $this->assertSame(0, StockMovement::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->count());
        $this->assertSame(0, Journal::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->count());
    }
}
