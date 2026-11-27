<?php

declare(strict_types=1);

namespace Modules\Transaction\Tests\Unit;

use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Core\Enums\CategoryType;
use Modules\Core\Enums\DiscountType;
use Modules\Core\Enums\ProductType;
use Modules\Core\Enums\ProductUnit;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Category;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductDiscount;
use Modules\Core\Models\ProductVariant;
use Modules\Core\Models\Voucher;
use Modules\Platform\Models\CompanySetting;
use Modules\Transaction\Enums\OrderChannel;
use Modules\Transaction\Enums\PaymentStatus;
use Modules\Transaction\Services\OrderCreationService;
use Modules\Transaction\Services\PosShiftService;
use Tests\TestCase;

/**
 * Unit tests verifying decimal quantity math, discounts, voucher quotas, tax, and snapshot immutability.
 */
class OrderCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure fractional laundry quantities, product discounts, vouchers, and tax calculate via bcmath.
     */
    public function test_creates_order_with_decimal_qty_product_discount_voucher_and_tax(): void
    {
        /** Arrange a laundry company with 10% tax, a discounted kilo variant, and a fixed voucher. */
        $company = Company::factory()->create(['business_type' => 'laundry']);
        CompanySetting::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'currency' => 'IDR',
            'tax_rate' => '10.00',
            'timezone' => 'Asia/Jakarta',
        ]);
        $branch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'MAIN',
            'name' => 'Laundry Pusat',
            'is_main' => true,
        ]);
        $category = Category::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'name' => 'Laundry Kiloan',
            'type' => CategoryType::Service,
        ]);
        $product = Product::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'category_id' => $category->getKey(),
            'name' => 'Cuci Setrika',
            'slug' => 'cuci-setrika',
            'type' => ProductType::Service,
            'track_stock' => false,
        ]);
        $variant = ProductVariant::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'name' => 'Reguler',
            'sku' => 'LND-REG',
            'unit' => ProductUnit::Kg,
            'cost_price' => '2500.0000',
            'selling_price' => '10000.0000',
            'is_active' => true,
        ]);

        /** 10% product discount -> 1000.0000 off per kg. */
        ProductDiscount::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'product_variant_id' => $variant->getKey(),
            'discount_type' => DiscountType::Percent,
            'discount_value' => '10.0000',
            'is_active' => true,
        ]);

        $voucher = Voucher::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'CUCI5K',
            'discount_type' => DiscountType::Fixed,
            'discount_value' => '5000.0000',
            'min_order_amount' => '20000.0000',
            'quota' => 5,
            'used_count' => 0,
        ]);

        /** Act by creating a 3.45 kg laundry order. */
        $order = app(OrderCreationService::class)->createOrder(
            company: $company,
            branch: $branch,
            channel: OrderChannel::Laundry,
            items: [
                [
                    'product_variant_id' => $variant->getKey(),
                    'qty' => '3.45',
                    'notes' => 'Pisah baju putih',
                ],
            ],
            voucherCode: 'cuci5k',
            rackLocation: 'RAK-A1',
        );

        /**
         * Assert exact bcmath figures:
         * gross = 3.45 * 10000 = 34500.0000
         * item_discount = 3.45 * 1000 = 3450.0000
         * voucher = 5000.0000 -> total discount = 8450.0000
         * taxable = 34500 - 8450 = 26050.0000
         * tax 10% = 2605.0000 -> grand_total = 28655.0000
         */
        $this->assertStringStartsWith('TRX-', $order->order_number);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertSame('34500.0000', $order->subtotal);
        $this->assertSame('8450.0000', $order->discount_total);
        $this->assertSame('2605.0000', $order->tax_total);
        $this->assertSame('28655.0000', $order->grand_total);
        $this->assertSame('RAK-A1', $order->rack_location);

        $item = $order->items->firstOrFail();
        $this->assertSame('3.45', $item->qty);
        $this->assertSame('kg', $item->unit_snapshot);
        $this->assertSame('10000.0000', $item->unit_price);
        $this->assertSame('2500.0000', $item->cost_price_snapshot);
        $this->assertSame('3450.0000', $item->discount_amount);
        $this->assertSame('31050.0000', $item->subtotal);

        $this->assertSame(1, $voucher->refresh()->used_count);
        $this->assertCount(1, $order->vouchers);
        $this->assertSame('CUCI5K', $order->vouchers->first()->voucher_code_snapshot);
        $this->assertSame('order_created', $order->logs->first()->event);
    }

    /**
     * Ensure order item price and cost snapshots remain unchanged after master variant prices are updated.
     */
    public function test_locks_price_and_cost_snapshots_when_master_variant_changes(): void
    {
        /** Arrange a company with a variant priced at 75,000 (cost 45,000). */
        $company = Company::factory()->create();
        $branch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'MAIN',
            'name' => 'Main',
            'is_main' => true,
        ]);
        $category = Category::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'name' => 'Goods',
            'type' => CategoryType::Product,
        ]);
        $product = Product::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'category_id' => $category->getKey(),
            'name' => 'Kopi',
            'slug' => 'kopi',
            'type' => ProductType::Goods,
            'track_stock' => true,
        ]);
        $variant = ProductVariant::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'name' => '250g',
            'sku' => 'KOPI-01',
            'unit' => ProductUnit::Pcs,
            'cost_price' => '45000.0000',
            'selling_price' => '75000.0000',
            'is_active' => true,
        ]);

        $order = app(OrderCreationService::class)->createOrder(
            company: $company,
            branch: $branch,
            channel: OrderChannel::Pos,
            items: [['product_variant_id' => $variant->getKey(), 'qty' => '2.00']],
        );

        /** Act by updating the master variant selling_price and cost_price afterwards. */
        $variant->update([
            'selling_price' => '99000.0000',
            'cost_price' => '60000.0000',
        ]);

        /** Assert historical order item snapshots remain frozen at purchase-time amounts. */
        $item = $order->items()->withoutGlobalScope('company')->firstOrFail();
        $this->assertSame('75000.0000', $item->unit_price);
        $this->assertSame('45000.0000', $item->cost_price_snapshot);
        $this->assertSame('150000.0000', $order->refresh()->grand_total);
    }

    /**
     * Ensure invalid items, closed shifts, exhausted vouchers, and cross-tenant branches are rejected.
     */
    public function test_rejects_empty_items_closed_shift_and_cross_tenant_relations(): void
    {
        /** Arrange two companies, an active variant, and a closed cashier shift. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $branchA = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyA->getKey(),
            'code' => 'MAIN',
            'name' => 'Branch A',
            'is_main' => true,
        ]);
        $branchB = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyB->getKey(),
            'code' => 'MAIN',
            'name' => 'Branch B',
            'is_main' => true,
        ]);
        $category = Category::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyA->getKey(),
            'name' => 'Goods',
            'type' => CategoryType::Product,
        ]);
        $product = Product::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyA->getKey(),
            'category_id' => $category->getKey(),
            'name' => 'Item',
            'slug' => 'item',
            'type' => ProductType::Goods,
        ]);
        $variant = ProductVariant::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyA->getKey(),
            'product_id' => $product->getKey(),
            'name' => 'Std',
            'sku' => 'ITM-01',
            'unit' => ProductUnit::Pcs,
            'cost_price' => '1000.0000',
            'selling_price' => '5000.0000',
            'is_active' => true,
        ]);

        $cashier = User::factory()->create();
        $shiftService = app(PosShiftService::class);
        $shift = $shiftService->openShift($companyA, $branchA, $cashier, '50000.0000');
        $shiftService->closeShift($shift, '50000.0000');

        $orderService = app(OrderCreationService::class);

        try {
            $orderService->createOrder($companyA, $branchA, OrderChannel::Pos, []);
            $this->fail('Expected InvalidArgumentException for empty items.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('at least one line item', $exception->getMessage());
        }

        try {
            $orderService->createOrder(
                company: $companyA,
                branch: $branchB,
                channel: OrderChannel::Pos,
                items: [['product_variant_id' => $variant->getKey(), 'qty' => '1.00']],
            );
            $this->fail('Expected InvalidArgumentException for cross-tenant branch.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Branch does not belong', $exception->getMessage());
        }

        $this->expectException(DomainException::class);
        $orderService->createOrder(
            company: $companyA,
            branch: $branchA,
            channel: OrderChannel::Pos,
            items: [['product_variant_id' => $variant->getKey(), 'qty' => '1.00']],
            posShift: $shift,
        );
    }
}
