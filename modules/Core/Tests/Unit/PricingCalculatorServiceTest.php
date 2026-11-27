<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit;

use App\Models\Company;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\DiscountType;
use Modules\Core\Enums\ProductType;
use Modules\Core\Enums\ProductUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductDiscount;
use Modules\Core\Models\ProductVariant;
use Modules\Core\Models\Voucher;
use Modules\Core\Services\PricingCalculatorService;
use Tests\TestCase;

/**
 * Unit tests verifying variant promotional pricing and voucher validation in PricingCalculatorService.
 */
class PricingCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    private PricingCalculatorService $service;

    /**
     * Resolve PricingCalculatorService before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PricingCalculatorService::class);
    }

    /**
     * Ensure percent and fixed discounts are calculated accurately and expired/inactive discounts are ignored.
     */
    public function test_calculates_best_active_variant_discount_and_ignores_expired_ones(): void
    {
        /** Arrange a variant priced at 100,000 with an expired 50% discount and active 15% + 10,000 discounts. */
        $company = Company::factory()->create();
        $product = Product::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'name' => 'Espresso Blend',
            'slug' => 'espresso-blend',
            'type' => ProductType::Goods,
        ]);
        $variant = ProductVariant::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'name' => '500g',
            'sku' => 'ESP-500',
            'unit' => ProductUnit::Pcs,
            'cost_price' => '60000.0000',
            'selling_price' => '100000.0000',
        ]);

        ProductDiscount::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'product_variant_id' => $variant->getKey(),
            'discount_type' => DiscountType::Percent,
            'discount_value' => '50.0000',
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subDay(),
            'is_active' => true,
        ]);

        ProductDiscount::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'product_variant_id' => $variant->getKey(),
            'discount_type' => DiscountType::Fixed,
            'discount_value' => '10000.0000',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        $bestPercent = ProductDiscount::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'product_variant_id' => $variant->getKey(),
            'discount_type' => DiscountType::Percent,
            'discount_value' => '15.0000',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        /** Act by calculating the effective price. */
        $result = $this->service->calculateVariantPrice($variant);

        /** Assert 15% discount (15,000) wins over 10,000 fixed and expired 50% is ignored. */
        $this->assertSame('100000.0000', $result->basePrice);
        $this->assertSame('15000.0000', $result->discountAmount);
        $this->assertSame('85000.0000', $result->finalPrice);
        $this->assertTrue($result->appliedDiscount->is($bestPercent));
    }

    /**
     * Ensure valid fixed and percent vouchers calculate exact savings and clamp fixed discounts at subtotal.
     */
    public function test_applies_valid_vouchers_and_clamps_fixed_discount_to_subtotal(): void
    {
        /** Arrange a company with percent and fixed vouchers. */
        $company = Company::factory()->create();

        Voucher::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'PROMO12',
            'discount_type' => DiscountType::Percent,
            'discount_value' => '12.5000',
            'min_order_amount' => '100000.0000',
            'quota' => 5,
            'used_count' => 2,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        Voucher::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'POTONG100K',
            'discount_type' => DiscountType::Fixed,
            'discount_value' => '100000.0000',
            'min_order_amount' => '50000.0000',
            'quota' => 10,
            'used_count' => 0,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        /** Act with case-insensitive voucher code lookup. */
        $percentResult = $this->service->applyVoucher($company, 'promo12', '200000.0000');
        $clampedFixed = $this->service->applyVoucher($company, 'POTONG100K', '75000.0000');

        /** Assert exact bcmath calculations and non-negative final total clamping. */
        $this->assertSame('25000.0000', $percentResult->discountAmount);
        $this->assertSame('175000.0000', $percentResult->finalTotal);

        $this->assertSame('75000.0000', $clampedFixed->discountAmount);
        $this->assertSame('0.0000', $clampedFixed->finalTotal);
    }

    /**
     * Ensure expired, exhausted, below-minimum, and cross-tenant vouchers are rejected cleanly.
     */
    public function test_rejects_expired_exhausted_below_minimum_and_cross_tenant_vouchers(): void
    {
        /** Arrange Company A and Company B with edge-case vouchers. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        Voucher::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyA->getKey(),
            'code' => 'EXPIRED',
            'discount_type' => DiscountType::Fixed,
            'discount_value' => '10000.0000',
            'min_order_amount' => '0.0000',
            'quota' => 10,
            'used_count' => 0,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->subMinute(),
        ]);

        Voucher::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyA->getKey(),
            'code' => 'FULLQUOTA',
            'discount_type' => DiscountType::Fixed,
            'discount_value' => '10000.0000',
            'min_order_amount' => '0.0000',
            'quota' => 3,
            'used_count' => 3,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        Voucher::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyA->getKey(),
            'code' => 'MIN100K',
            'discount_type' => DiscountType::Fixed,
            'discount_value' => '10000.0000',
            'min_order_amount' => '100000.0000',
            'quota' => 10,
            'used_count' => 0,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $this->assertDomainFailure(fn () => $this->service->applyVoucher($companyA, 'EXPIRED', '50000.0000'), 'expired');
        $this->assertDomainFailure(fn () => $this->service->applyVoucher($companyA, 'FULLQUOTA', '50000.0000'), 'quota');
        $this->assertDomainFailure(fn () => $this->service->applyVoucher($companyA, 'MIN100K', '99999.9999'), 'minimum');
        $this->assertDomainFailure(fn () => $this->service->applyVoucher($companyB, 'MIN100K', '150000.0000'), 'invalid');
    }

    /**
     * Assert that executing the given callback throws a DomainException containing the expected fragment.
     */
    private function assertDomainFailure(callable $callback, string $expectedMessageFragment): void
    {
        try {
            $callback();
            $this->fail("Expected DomainException containing '{$expectedMessageFragment}'.");
        } catch (DomainException $exception) {
            $this->assertStringContainsString($expectedMessageFragment, strtolower($exception->getMessage()));
        }
    }
}
