<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit;

use App\Models\Company;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Core\Enums\ProductType;
use Modules\Core\Enums\ProductUnit;
use Modules\Core\Enums\StockMovementType;
use Modules\Core\Models\Branch;
use Modules\Core\Models\InventoryStock;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductVariant;
use Modules\Core\Models\StockMovement;
use Modules\Core\Services\StockMovementService;
use Tests\TestCase;

/**
 * Unit tests verifying precision, negative stock rejection, transfers, and tenant guards in StockMovementService.
 */
class StockMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockMovementService $service;

    /**
     * Resolve StockMovementService before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(StockMovementService::class);
    }

    /**
     * Ensure stock in, stock out, and adjustment maintain exact DECIMAL(12,2) balances and append ledger rows.
     */
    public function test_records_stock_in_out_and_adjustment_with_decimal_precision(): void
    {
        /** Arrange a company, branch, and tracked product variant. */
        [$company, $branch, $variant] = $this->createBranchAndVariant();

        /** Act: stock in 12.50, stock out 2.25, then adjust to 20.00. */
        $in = $this->service->recordStockIn($company, $branch, $variant, '12.50', '40000.0000', 'purchase', 1);
        $out = $this->service->recordStockOut($company, $branch, $variant, '2.25', 'order', 10);
        $adj = $this->service->adjustStock($company, $branch, $variant, '20.00', 'Stock opname');

        $stock = InventoryStock::query()->withoutGlobalScope('company')
            ->where('branch_id', $branch->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->firstOrFail();

        /** Assert exact final quantity and immutable movement ledger values. */
        $this->assertSame('20.00', $stock->quantity);
        $this->assertSame(StockMovementType::In, $in->type);
        $this->assertSame('12.50', $in->qty_change);
        $this->assertSame(StockMovementType::Out, $out->type);
        $this->assertSame('-2.25', $out->qty_change);
        $this->assertSame(StockMovementType::Adjustment, $adj->type);
        $this->assertSame('9.75', $adj->qty_change);
        $this->assertSame(3, StockMovement::query()->withoutGlobalScope('company')->count());
    }

    /**
     * Ensure inter-branch transfers deduct source stock and credit destination stock atomically.
     */
    public function test_transfers_stock_between_branches_within_same_company(): void
    {
        /** Arrange two branches under the same company with 30.00 stock at source. */
        [$company, $sourceBranch, $variant] = $this->createBranchAndVariant();
        $destinationBranch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'CAB-02',
            'name' => 'Cabang Selatan',
            'is_main' => false,
        ]);

        $this->service->recordStockIn($company, $sourceBranch, $variant, '30.00', '40000.0000');

        /** Act by transferring 11.50 units from source to destination. */
        $result = $this->service->transferStock($company, $sourceBranch, $destinationBranch, $variant, '11.50');

        $sourceQty = InventoryStock::query()->withoutGlobalScope('company')->where('branch_id', $sourceBranch->getKey())->value('quantity');
        $destQty = InventoryStock::query()->withoutGlobalScope('company')->where('branch_id', $destinationBranch->getKey())->value('quantity');

        /** Assert exact post-transfer balances and two paired transfer movements. */
        $this->assertSame('18.50', $sourceQty);
        $this->assertSame('11.50', $destQty);
        $this->assertSame('-11.50', $result['out']->qty_change);
        $this->assertSame('11.50', $result['in']->qty_change);
        $this->assertSame(StockMovementType::Transfer, $result['out']->type);
        $this->assertSame(StockMovementType::Transfer, $result['in']->type);
    }

    /**
     * Ensure outbound and transfer operations reject negative stock balances.
     */
    public function test_rejects_negative_stock_on_stock_out_and_transfer(): void
    {
        /** Arrange two branches with 5.00 units in source branch. */
        [$company, $branchA, $variant] = $this->createBranchAndVariant();
        $branchB = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'CAB-02',
            'name' => 'Cabang 2',
        ]);

        $this->service->recordStockIn($company, $branchA, $variant, '5.00', '10000.0000');

        /** Assert stock out exceeding balance throws DomainException. */
        try {
            $this->service->recordStockOut($company, $branchA, $variant, '5.01');
            $this->fail('Expected DomainException when deducting more than available stock.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Insufficient branch stock', $exception->getMessage());
        }

        /** Assert transfer exceeding balance throws DomainException and leaves stock unchanged. */
        try {
            $this->service->transferStock($company, $branchA, $branchB, $variant, '6.00');
            $this->fail('Expected DomainException when transferring more than available stock.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Insufficient source branch stock', $exception->getMessage());
        }

        $this->assertSame('5.00', InventoryStock::query()->withoutGlobalScope('company')->where('branch_id', $branchA->getKey())->value('quantity'));
    }

    /**
     * Ensure cross-tenant branches or variants and invalid quantities are rejected.
     */
    public function test_rejects_cross_tenant_entities_and_invalid_quantities(): void
    {
        /** Arrange entities belonging to two separate companies. */
        [$companyA, $branchA, $variantA] = $this->createBranchAndVariant();
        [$companyB, $branchB, $variantB] = $this->createBranchAndVariant();

        $this->service->recordStockIn($companyA, $branchA, $variantA, '10.00');

        /** Assert cross-tenant branch in transfer throws InvalidArgumentException. */
        try {
            $this->service->transferStock($companyA, $branchA, $branchB, $variantA, '2.00');
            $this->fail('Expected InvalidArgumentException for cross-tenant destination branch.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('does not belong', $exception->getMessage());
        }

        /** Assert cross-tenant variant throws InvalidArgumentException. */
        try {
            $this->service->recordStockIn($companyA, $branchA, $variantB, '2.00');
            $this->fail('Expected InvalidArgumentException for cross-tenant variant.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('does not belong', $exception->getMessage());
        }

        /** Assert zero or negative quantity throws InvalidArgumentException. */
        $this->expectException(InvalidArgumentException::class);
        $this->service->recordStockIn($companyB, $branchB, $variantB, '0.00');
    }

    /**
     * Helper creating an isolated company, main branch, product, and active variant.
     *
     * @return array{0: Company, 1: Branch, 2: ProductVariant}
     */
    private function createBranchAndVariant(): array
    {
        $company = Company::factory()->create();

        $branch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'MAIN',
            'name' => 'Main Branch',
            'is_main' => true,
        ]);

        $product = Product::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'name' => 'Test Goods',
            'slug' => 'test-goods-'.$company->getKey(),
            'type' => ProductType::Goods,
            'track_stock' => true,
        ]);

        $variant = ProductVariant::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'name' => 'Standard',
            'sku' => 'SKU-'.$company->getKey(),
            'unit' => ProductUnit::Pcs,
            'cost_price' => '40000.0000',
            'selling_price' => '60000.0000',
            'is_active' => true,
        ]);

        return [$company, $branch, $variant];
    }
}
