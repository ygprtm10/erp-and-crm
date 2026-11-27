<?php

declare(strict_types=1);

namespace Modules\Transaction\Tests\Unit;

use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Core\Enums\CategoryType;
use Modules\Core\Enums\ProductType;
use Modules\Core\Enums\ProductUnit;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Category;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductVariant;
use Modules\Transaction\Enums\OrderChannel;
use Modules\Transaction\Enums\PaymentMethod;
use Modules\Transaction\Enums\PosShiftStatus;
use Modules\Transaction\Services\OrderCreationService;
use Modules\Transaction\Services\OrderPaymentService;
use Modules\Transaction\Services\PosShiftService;
use Tests\TestCase;

/**
 * Unit tests verifying PosShiftService open/close guards and bcmath expected cash reconciliation.
 */
class PosShiftServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure opening a second concurrent shift for the same cashier and branch is rejected.
     */
    public function test_opens_shift_and_rejects_duplicate_open_shift_for_same_cashier_and_branch(): void
    {
        /** Arrange a company, branch, and cashier user. */
        $company = Company::factory()->create();
        $branch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'MAIN',
            'name' => 'Main Branch',
            'is_main' => true,
        ]);
        $cashier = User::factory()->create();

        $service = app(PosShiftService::class);
        $shift = $service->openShift($company, $branch, $cashier, '250000.0000');

        /** Assert initial shift state matches opening cash. */
        $this->assertSame(PosShiftStatus::Open, $shift->status);
        $this->assertSame('250000.0000', $shift->opening_cash);
        $this->assertSame('250000.0000', $shift->expected_cash);

        /** Assert opening another concurrent shift on the same branch throws DomainException. */
        $this->expectException(DomainException::class);
        $service->openShift($company, $branch, $cashier, '100000.0000');
    }

    /**
     * Ensure closing a shift sums opening_cash plus paid cash payments while ignoring non-cash payments.
     */
    public function test_computes_expected_cash_accurately_with_bcmath_when_closing_shift(): void
    {
        /** Arrange a company, branch, product variant, and open shift. */
        $company = Company::factory()->create();
        $branch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'MAIN',
            'name' => 'Main Branch',
            'is_main' => true,
        ]);
        $cashier = User::factory()->create();
        $category = Category::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'name' => 'Retail',
            'type' => CategoryType::Product,
        ]);
        $product = Product::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'category_id' => $category->getKey(),
            'name' => 'Snack',
            'slug' => 'snack',
            'type' => ProductType::Goods,
            'track_stock' => false,
        ]);
        $variant = ProductVariant::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'name' => 'Regular',
            'sku' => 'SNK-01',
            'unit' => ProductUnit::Pcs,
            'cost_price' => '10000.0000',
            'selling_price' => '25000.0000',
            'is_active' => true,
        ]);

        $shiftService = app(PosShiftService::class);
        $orderService = app(OrderCreationService::class);
        $paymentService = app(OrderPaymentService::class);

        $shift = $shiftService->openShift($company, $branch, $cashier, '150000.0000');

        /** Create a cash-paid order (50,000) and a transfer-paid order (25,000) in the same shift. */
        $cashOrder = $orderService->createOrder(
            company: $company,
            branch: $branch,
            channel: OrderChannel::Pos,
            items: [['product_variant_id' => $variant->getKey(), 'qty' => '2.00']],
            posShift: $shift,
        );
        $paymentService->recordManualPayment($cashOrder, PaymentMethod::Cash, '50000.0000');

        $transferOrder = $orderService->createOrder(
            company: $company,
            branch: $branch,
            channel: OrderChannel::Pos,
            items: [['product_variant_id' => $variant->getKey(), 'qty' => '1.00']],
            posShift: $shift,
        );
        $paymentService->recordManualPayment($transferOrder, PaymentMethod::Transfer, '25000.0000');

        /** Act by closing the shift and counting physical cash. */
        $closed = $shiftService->closeShift($shift, '200000.0000');

        /** Assert expected_cash = 150,000 + 50,000 (transfer excluded). */
        $this->assertSame(PosShiftStatus::Closed, $closed->status);
        $this->assertSame('200000.0000', $closed->expected_cash);
        $this->assertSame('200000.0000', $closed->actual_cash);
        $this->assertNotNull($closed->closed_at);
    }

    /**
     * Ensure cross-company branches and closing an already closed shift are rejected.
     */
    public function test_rejects_cross_company_branch_and_double_closing_shift(): void
    {
        /** Arrange two distinct companies and a shift belonging to company A. */
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
        $cashier = User::factory()->create();

        $service = app(PosShiftService::class);

        try {
            $service->openShift($companyA, $branchB, $cashier, '0.0000');
            $this->fail('Expected InvalidArgumentException for cross-company branch.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Branch does not belong', $exception->getMessage());
        }

        $shift = $service->openShift($companyA, $branchA, $cashier, '100000.0000');
        $service->closeShift($shift, '100000.0000');

        $this->expectException(DomainException::class);
        $service->closeShift($shift, '100000.0000');
    }
}
