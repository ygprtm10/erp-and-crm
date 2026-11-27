<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit;

use App\Models\Company;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Database\Seeders\CoreDemoSeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Category;
use Modules\Core\Models\Contact;
use Modules\Core\Models\InventoryStock;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductVariant;
use Modules\Core\Models\StockMovement;
use Modules\Core\Models\Voucher;
use Modules\Platform\Database\Seeders\SaasBundleSeeder;
use Modules\Platform\Database\Seeders\SaasModuleSeeder;
use Modules\Platform\Database\Seeders\SuperAdminSeeder;
use Modules\Platform\Database\Seeders\TenantDemoSeeder;
use Modules\Platform\Events\CompanyRegistered;
use Tests\TestCase;

/**
 * Unit tests verifying Core model tenant isolation, CompanyRegistered branch provisioning, and CoreDemoSeeder idempotency.
 */
class CoreTenancyAndSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Clear any active Filament tenant after each test.
     */
    protected function tearDown(): void
    {
        Filament::setTenant(null);

        parent::tearDown();
    }

    /**
     * Ensure CoreDemoSeeder populates distinct isolated data for Toko Maju and Gym Sehat idempotently.
     */
    public function test_core_demo_seeder_is_idempotent_and_isolates_tenant_datasets(): void
    {
        /** Act by running Platform and Core seeders twice consecutively. */
        $seeders = [
            SuperAdminSeeder::class,
            SaasModuleSeeder::class,
            SaasBundleSeeder::class,
            TenantDemoSeeder::class,
            CoreDemoSeeder::class,
        ];
        $this->seed($seeders);
        $this->seed($seeders);

        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        /** Assert exact record counts without duplication across consecutive seed runs. */
        $this->assertSame(2, Branch::query()->withoutGlobalScope('company')->count());
        $this->assertSame(3, Contact::query()->withoutGlobalScope('company')->count());
        $this->assertSame(2, Category::query()->withoutGlobalScope('company')->count());
        $this->assertSame(2, Product::query()->withoutGlobalScope('company')->count());
        $this->assertSame(2, ProductVariant::query()->withoutGlobalScope('company')->count());
        $this->assertSame(1, InventoryStock::query()->withoutGlobalScope('company')->count());
        $this->assertSame(1, StockMovement::query()->withoutGlobalScope('company')->count());
        $this->assertSame(1, Voucher::query()->withoutGlobalScope('company')->count());

        /** Assert BelongsToCompany strictly scopes queries when switching active Filament tenant. */
        Filament::setTenant($tokoMaju, isQuiet: true);
        $this->assertSame(['Kopi Arabika 250g'], Product::query()->pluck('name')->all());
        $this->assertSame(['HEMAT10'], Voucher::query()->pluck('code')->all());
        $this->assertSame(['50.00'], InventoryStock::query()->pluck('quantity')->all());

        Filament::setTenant($gymSehat, isQuiet: true);
        $this->assertSame(['Paket Bulanan All-Access'], Product::query()->pluck('name')->all());
        $this->assertFalse(Product::query()->firstOrFail()->track_stock);
        $this->assertSame([], Voucher::query()->pluck('code')->all());
        $this->assertSame([], InventoryStock::query()->pluck('quantity')->all());
    }

    /**
     * Ensure CompanyRegistered listener automatically provisions a MAIN branch idempotently.
     */
    public function test_company_registered_listener_provisions_main_branch_idempotently(): void
    {
        /** Arrange a new company. */
        $company = Company::factory()->create(['name' => 'Laundry Bersih']);

        /** Act by dispatching CompanyRegistered twice. */
        CompanyRegistered::dispatch($company);
        CompanyRegistered::dispatch($company);

        /** Assert a single MAIN branch exists for the company. */
        $branches = Branch::query()->withoutGlobalScope('company')->where('company_id', $company->getKey())->get();

        $this->assertCount(1, $branches);
        $this->assertSame('MAIN', $branches->first()->code);
        $this->assertTrue($branches->first()->is_main);
    }
}
