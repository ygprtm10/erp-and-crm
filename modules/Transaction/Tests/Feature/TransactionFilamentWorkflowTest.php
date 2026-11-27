<?php

declare(strict_types=1);

namespace Modules\Transaction\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Core\Models\Branch;
use Modules\Core\Models\InventoryStock;
use Modules\Core\Models\ProductVariant;
use Modules\Transaction\Enums\OrderChannel;
use Modules\Transaction\Enums\OrderStatus;
use Modules\Transaction\Enums\PaymentMethod;
use Modules\Transaction\Enums\PaymentStatus;
use Modules\Transaction\Enums\PosShiftStatus;
use Modules\Transaction\Filament\Pages\PosCashierPage;
use Modules\Transaction\Filament\Resources\OrderResource\Pages\ViewOrder;
use Modules\Transaction\Filament\Resources\PosShiftResource\Pages\ListPosShifts;
use Modules\Transaction\Models\Order;
use Modules\Transaction\Models\OrderStage;
use Modules\Transaction\Models\PosShift;
use Modules\Transaction\Services\OrderCreationService;
use Tests\TestCase;

/**
 * Feature tests verifying PosCashierPage checkout, PosShift actions, and OrderResource workflow actions.
 */
class TransactionFilamentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure cashier closes seeded shift, opens new shift, checks out with voucher, deducts stock, and posts journal.
     */
    public function test_full_pos_cashier_flow_from_shift_to_voucher_checkout_stock_and_journal(): void
    {
        /** Arrange seeded Toko Maju (stock 48.00, 1 open shift, 3 journals). */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $branch = Branch::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $variant = ProductVariant::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->where('sku', 'KOPI-ARB-250')->firstOrFail();
        $seededShift = PosShift::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Step 1: Close existing open shift via ListPosShifts table action (opening 200k + cash sale 150k = 350k). */
        Livewire::test(ListPosShifts::class)
            ->callAction(TestAction::make('closeShift')->table($seededShift), data: [
                'actual_cash' => '350000',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(PosShiftStatus::Closed, $seededShift->refresh()->status);
        $this->assertSame('350000.0000', $seededShift->expected_cash);

        /** PosCashierPage should display guided no-shift state before opening a fresh shift. */
        Livewire::test(PosCashierPage::class)
            ->assertSee('Shift Kasir Belum Dibuka');

        /** Step 2: Open a fresh cashier shift via ListPosShifts header action. */
        Livewire::test(ListPosShifts::class)
            ->callAction('openShift', data: [
                'branch_id' => $branch->getKey(),
                'opening_cash' => '100000',
            ])
            ->assertHasNoActionErrors();

        /** Step 3: Add Kopi Arabika (75,000) to cart, apply HEMAT10 voucher (-7,500 -> 67,500), and checkout via Cash. */
        Livewire::test(PosCashierPage::class)
            ->assertSee('Shift Aktif')
            ->call('addToCart', $variant->getKey())
            ->set('voucherCode', 'HEMAT10')
            ->call('applyVoucher')
            ->assertSet('appliedVoucherCode', 'HEMAT10')
            ->assertSet('voucherFeedbackValid', true)
            ->set('paymentMethod', PaymentMethod::Cash->value)
            ->call('checkout')
            ->assertSet('cart', []);

        /** Assert new paid order created (67,500.0000). */
        $newOrder = Order::query()
            ->where('order_number', '!=', 'TRX-SEED-001')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(PaymentStatus::Paid, $newOrder->payment_status);
        $this->assertSame('75000.0000', $newOrder->subtotal);
        $this->assertSame('7500.0000', $newOrder->discount_total);
        $this->assertSame('67500.0000', $newOrder->grand_total);

        /** Assert inventory stock decreased from 48.00 to 47.00. */
        $stock = InventoryStock::query()->where('product_variant_id', $variant->getKey())->firstOrFail();
        $this->assertSame('47.00', $stock->quantity);

        /** Assert balanced Revenue (67,500) + COGS (45,000) journal posted automatically. */
        $journal = Journal::query()
            ->with('items')
            ->where('reference_type', Order::class)
            ->where('reference_id', $newOrder->getKey())
            ->firstOrFail();

        $this->assertSame(JournalStatus::Posted, $journal->status);

        $accounts = Account::query()->get()->keyBy('code');
        $itemsByAccount = $journal->items->keyBy('account_id');

        $this->assertSame('67500.0000', $itemsByAccount->get($accounts->get('1101')->getKey())->debit);
        $this->assertSame('67500.0000', $itemsByAccount->get($accounts->get('4101')->getKey())->credit);
        $this->assertSame('45000.0000', $itemsByAccount->get($accounts->get('5101')->getKey())->debit);
        $this->assertSame('45000.0000', $itemsByAccount->get($accounts->get('1201')->getKey())->credit);
    }

    /**
     * Ensure OrderResource ViewOrder executes recordPayment, updateStage, and cancelOrder actions properly.
     */
    public function test_order_resource_executes_payment_stage_and_cancel_actions(): void
    {
        /** Arrange Toko Maju with two unpaid orders. */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $branch = Branch::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $variant = ProductVariant::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->where('sku', 'KOPI-ARB-250')->firstOrFail();
        $cashAccount = Account::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->where('code', '1101')->firstOrFail();

        $orderService = app(OrderCreationService::class);
        $orderToSettle = $orderService->createOrder(
            company: $tokoMaju,
            branch: $branch,
            channel: OrderChannel::Invoice,
            items: [['product_variant_id' => $variant->getKey(), 'qty' => '1.00']],
            orderNumber: 'TRX-INV-100',
        );
        $orderToCancel = $orderService->createOrder(
            company: $tokoMaju,
            branch: $branch,
            channel: OrderChannel::Invoice,
            items: [['product_variant_id' => $variant->getKey(), 'qty' => '1.00']],
            orderNumber: 'TRX-INV-101',
        );

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Act 1: Update operational stage & rack location on ViewOrder. */
        Livewire::test(ViewOrder::class, ['record' => $orderToSettle->getKey()])
            ->callAction('updateStage', data: [
                'stage_name' => 'Packing & Siap Kirim',
                'rack_location' => 'RAK-B2',
                'notes' => 'Packed securely',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('RAK-B2', $orderToSettle->refresh()->rack_location);
        $this->assertTrue(OrderStage::query()->where('order_id', $orderToSettle->getKey())->where('stage_name', 'Packing & Siap Kirim')->exists());

        /** Act 2: Record full manual payment via ViewOrder recordPayment action. */
        Livewire::test(ViewOrder::class, ['record' => $orderToSettle->getKey()])
            ->callAction('recordPayment', data: [
                'payment_method' => PaymentMethod::Cash->value,
                'account_id' => $cashAccount->getKey(),
                'amount' => '75000.0000',
                'notes' => 'Invoice settled at counter',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(PaymentStatus::Paid, $orderToSettle->refresh()->payment_status);
        $this->assertSame('47.00', InventoryStock::query()->where('product_variant_id', $variant->getKey())->firstOrFail()->quantity);
        $this->assertTrue(Journal::query()->where('reference_type', Order::class)->where('reference_id', $orderToSettle->getKey())->exists());

        /** Act 3: Cancel unpaid order via ViewOrder cancelOrder action. */
        Livewire::test(ViewOrder::class, ['record' => $orderToCancel->getKey()])
            ->callAction('cancelOrder', data: [
                'reason' => 'Customer requested cancellation',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(OrderStatus::Cancelled, $orderToCancel->refresh()->order_status);
    }
}
