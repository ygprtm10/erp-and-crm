<?php

declare(strict_types=1);

namespace Modules\Core\Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;
use Modules\Core\Enums\CategoryType;
use Modules\Core\Enums\DiscountType;
use Modules\Core\Enums\ProductType;
use Modules\Core\Enums\ProductUnit;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Category;
use Modules\Core\Models\Contact;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductVariant;
use Modules\Core\Models\StockMovement;
use Modules\Core\Models\Voucher;
use Modules\Core\Services\StockMovementService;

/**
 * Seeds isolated demo master data, inventory, and vouchers for Toko Maju and Gym Sehat.
 */
class CoreDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->first();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->first();

        if ($tokoMaju instanceof Company) {
            $this->seedTokoMaju($tokoMaju);
        }

        if ($gymSehat instanceof Company) {
            $this->seedGymSehat($gymSehat);
        }
    }

    /**
     * Seed retail branch, customer/vendor contacts, tracked goods product, initial stock, and voucher for Toko Maju.
     */
    private function seedTokoMaju(Company $company): void
    {
        $companyId = $company->getKey();

        $mainBranch = Branch::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'code' => 'MAIN'],
                [
                    'name' => 'Toko Maju - Pusat',
                    'address' => 'Jl. Sudirman No. 10, Jakarta',
                    'phone' => '0215550101',
                    'is_main' => true,
                ],
            );

        Contact::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'code' => 'CUST-001'],
                [
                    'name' => 'Budi Santoso',
                    'email' => 'budi@example.com',
                    'phone' => '081311112222',
                    'is_customer' => true,
                    'is_vendor' => false,
                    'is_member' => false,
                    'lead_stage' => 'qualified',
                ],
            );

        Contact::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'code' => 'VEND-001'],
                [
                    'name' => 'PT Kopi Nusantara',
                    'email' => 'supply@kopinusantara.test',
                    'phone' => '0215550909',
                    'is_customer' => false,
                    'is_vendor' => true,
                    'is_member' => false,
                ],
            );

        $category = Category::query()
            ->withoutGlobalScope('company')
            ->firstOrCreate(
                [
                    'company_id' => $companyId,
                    'name' => 'Produk Retail',
                    'type' => CategoryType::Product,
                ],
            );

        $product = Product::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'slug' => 'kopi-arabika-250g'],
                [
                    'category_id' => $category->getKey(),
                    'name' => 'Kopi Arabika 250g',
                    'type' => ProductType::Goods,
                    'track_stock' => true,
                    'show_on_web' => true,
                ],
            );

        $variant = ProductVariant::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'sku' => 'KOPI-ARB-250'],
                [
                    'product_id' => $product->getKey(),
                    'name' => 'Kemasan 250g',
                    'barcode' => '8991002003001',
                    'unit' => ProductUnit::Pcs,
                    'cost_price' => '45000.0000',
                    'selling_price' => '75000.0000',
                    'is_active' => true,
                ],
            );

        /** Seed initial opening stock exactly once to preserve seeder idempotency. */
        $alreadySeeded = StockMovement::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('branch_id', $mainBranch->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->where('reference_type', 'seeder')
            ->exists();

        if (! $alreadySeeded) {
            app(StockMovementService::class)->recordStockIn(
                company: $company,
                branch: $mainBranch,
                variant: $variant,
                quantity: '50.00',
                unitCost: '45000.0000',
                referenceType: 'seeder',
                referenceId: $variant->getKey(),
                notes: 'Initial opening stock for Toko Maju demo',
            );
        }

        Voucher::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'code' => 'HEMAT10'],
                [
                    'discount_type' => DiscountType::Percent,
                    'discount_value' => '10.0000',
                    'min_order_amount' => '50000.0000',
                    'quota' => 100,
                    'used_count' => 0,
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addMonth(),
                ],
            );
    }

    /**
     * Seed gym branch, member contact, and non-stock-tracked membership package for Gym Sehat.
     */
    private function seedGymSehat(Company $company): void
    {
        $companyId = $company->getKey();

        Branch::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'code' => 'MAIN'],
                [
                    'name' => 'Gym Sehat - Utama',
                    'address' => 'Jl. Kemang Raya No. 25, Jakarta',
                    'phone' => '0215550202',
                    'is_main' => true,
                ],
            );

        Contact::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'code' => 'MEM-001'],
                [
                    'name' => 'Andi Pratama',
                    'email' => 'andi@example.com',
                    'phone' => '081399998888',
                    'is_customer' => true,
                    'is_vendor' => false,
                    'is_member' => true,
                ],
            );

        $category = Category::query()
            ->withoutGlobalScope('company')
            ->firstOrCreate(
                [
                    'company_id' => $companyId,
                    'name' => 'Paket Membership',
                    'type' => CategoryType::Membership,
                ],
            );

        $product = Product::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'slug' => 'paket-bulanan-all-access'],
                [
                    'category_id' => $category->getKey(),
                    'name' => 'Paket Bulanan All-Access',
                    'type' => ProductType::Membership,
                    'track_stock' => false,
                    'show_on_web' => true,
                ],
            );

        ProductVariant::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'sku' => 'GYM-MONTHLY-01'],
                [
                    'product_id' => $product->getKey(),
                    'name' => '1 Bulan Unlimited',
                    'unit' => ProductUnit::Paket,
                    'cost_price' => '0.0000',
                    'selling_price' => '350000.0000',
                    'is_active' => true,
                ],
            );
    }
}
