<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Enums\DiscountType;
use Modules\Core\Enums\InteractionChannel;
use Modules\Core\Enums\ProductUnit;
use Modules\Core\Filament\CorePlugin;
use Modules\Core\Filament\Resources\BranchResource;
use Modules\Core\Filament\Resources\BranchResource\Pages\CreateBranch;
use Modules\Core\Filament\Resources\BranchResource\Pages\ListBranches;
use Modules\Core\Filament\Resources\CategoryResource;
use Modules\Core\Filament\Resources\CategoryResource\Pages\ListCategories;
use Modules\Core\Filament\Resources\ContactResource;
use Modules\Core\Filament\Resources\ContactResource\Pages\EditContact;
use Modules\Core\Filament\Resources\ContactResource\Pages\ListContacts;
use Modules\Core\Filament\Resources\ContactResource\RelationManagers\InteractionsRelationManager;
use Modules\Core\Filament\Resources\InventoryStockResource;
use Modules\Core\Filament\Resources\ProductResource;
use Modules\Core\Filament\Resources\ProductResource\Pages\EditProduct;
use Modules\Core\Filament\Resources\ProductResource\Pages\ListProducts;
use Modules\Core\Filament\Resources\ProductResource\RelationManagers\DiscountsRelationManager;
use Modules\Core\Filament\Resources\ProductResource\RelationManagers\VariantsRelationManager;
use Modules\Core\Filament\Resources\VoucherResource;
use Modules\Core\Filament\Resources\VoucherResource\Pages\CreateVoucher;
use Modules\Core\Filament\Resources\VoucherResource\Pages\ListVouchers;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Category;
use Modules\Core\Models\Contact;
use Modules\Core\Models\InventoryStock;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductDiscount;
use Modules\Core\Models\ProductVariant;
use Modules\Core\Models\Voucher;
use Tests\TestCase;

/**
 * Feature tests verifying Core Filament 5 plugin registration, CRUD, relation managers, and tenant isolation.
 */
class CoreResourcesCrudAndIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure CorePlugin is registered on the /app panel and all Core resource routes respond properly.
     */
    public function test_core_plugin_registers_all_resources_on_app_panel(): void
    {
        /** Arrange seeded Toko Maju tenant and owner. */
        $this->seed(DatabaseSeeder::class);

        $appPanel = Filament::getPanel('app');
        $this->assertTrue($appPanel->hasPlugin('core'));
        $this->assertSame('core', CorePlugin::make()->getId());

        $owner = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();

        $this->actingAs($owner);
        Filament::setCurrentPanel($appPanel);
        Filament::setTenant($tokoMaju, isQuiet: true);

        $stock = InventoryStock::query()->firstOrFail();

        /** Assert all 6 Core resource listing/view endpoints render 200 OK. */
        $this->get(BranchResource::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(ContactResource::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(CategoryResource::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(ProductResource::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(VoucherResource::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(InventoryStockResource::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(InventoryStockResource::getUrl('view', ['tenant' => $tokoMaju, 'record' => $stock]))->assertOk();

        /** Assert InventoryStockResource disallows direct CRUD form creation/editing. */
        $this->assertFalse(InventoryStockResource::canCreate());
        $this->assertFalse(InventoryStockResource::canEdit($stock));
    }

    /**
     * Ensure tenant CRUD works with scopedUnique validation and relation managers.
     */
    public function test_tenant_can_create_core_records_and_manage_relations(): void
    {
        /** Arrange seeded demo tenants. */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $owner2 = User::query()->where('email', 'owner2@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Create a secondary branch CAB-02 and voucher PROMO20 in Toko Maju. */
        Livewire::test(CreateBranch::class)
            ->fillForm([
                'code' => 'CAB-02',
                'name' => 'Cabang Kemang',
                'is_main' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateVoucher::class)
            ->fillForm([
                'code' => 'PROMO20',
                'discount_type' => DiscountType::Percent->value,
                'discount_value' => '20',
                'min_order_amount' => '100000',
                'quota' => 50,
                'used_count' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        /** Test Contact InteractionsRelationManager auto-assigns user_id and saves interaction. */
        $contact = Contact::query()->firstOrFail();
        Livewire::test(InteractionsRelationManager::class, [
            'ownerRecord' => $contact,
            'pageClass' => EditContact::class,
        ])
            ->callTableAction('create', data: [
                'channel' => InteractionChannel::Wa->value,
                'notes' => 'Sent catalog via WhatsApp',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame($owner1->getKey(), $contact->interactions()->firstOrFail()->user_id);

        /** Test Product VariantsRelationManager and DiscountsRelationManager. */
        $product = Product::query()->firstOrFail();
        Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->callTableAction('create', data: [
                'name' => 'Kemasan 500g',
                'sku' => 'KOPI-ARB-500',
                'unit' => ProductUnit::Pcs->value,
                'cost_price' => '80000',
                'selling_price' => '140000',
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $newVariant = ProductVariant::query()->where('sku', 'KOPI-ARB-500')->firstOrFail();

        Livewire::test(DiscountsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->callTableAction('create', data: [
                'product_variant_id' => $newVariant->getKey(),
                'discount_type' => DiscountType::Fixed->value,
                'discount_value' => '15000',
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, ProductDiscount::query()->where('product_variant_id', $newVariant->getKey())->count());

        /** Switch to Gym Sehat and confirm scopedUnique allows reusing code CAB-02 in another company. */
        $this->flushSession();
        $this->actingAs($owner2);
        Filament::setTenant($gymSehat, isQuiet: true);

        Livewire::test(CreateBranch::class)
            ->fillForm([
                'code' => 'CAB-02',
                'name' => 'Gym Sehat Cabang 2',
                'is_main' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Branch::query()->withoutGlobalScope('company')->where('code', 'CAB-02')->count());
    }

    /**
     * Ensure cross-tenant records are invisible in tables and rejected with 404 on direct edit URLs.
     */
    public function test_enforces_strict_cross_tenant_isolation_across_core_resources(): void
    {
        /** Arrange seeded datasets for Toko Maju and Gym Sehat. */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        $tokoBranch = Branch::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $gymBranch = Branch::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->firstOrFail();
        $tokoContact = Contact::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $gymContact = Contact::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->firstOrFail();
        $tokoCategory = Category::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $gymCategory = Category::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->firstOrFail();
        $tokoProduct = Product::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $gymProduct = Product::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->firstOrFail();
        $tokoVoucher = Voucher::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $gymVoucher = Voucher::query()->withoutGlobalScope('company')->create([
            'company_id' => $gymSehat->getKey(),
            'code' => 'GYMFIT',
            'discount_type' => DiscountType::Percent->value,
            'discount_value' => '10.0000',
            'min_order_amount' => '0.0000',
            'quota' => 10,
            'used_count' => 0,
        ]);

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Assert each Livewire resource table displays only Toko Maju records. */
        Livewire::test(ListBranches::class)->assertCanSeeTableRecords([$tokoBranch])->assertCanNotSeeTableRecords([$gymBranch]);
        Livewire::test(ListContacts::class)->assertCanSeeTableRecords([$tokoContact])->assertCanNotSeeTableRecords([$gymContact]);
        Livewire::test(ListCategories::class)->assertCanSeeTableRecords([$tokoCategory])->assertCanNotSeeTableRecords([$gymCategory]);
        Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$tokoProduct])->assertCanNotSeeTableRecords([$gymProduct]);
        Livewire::test(ListVouchers::class)->assertCanSeeTableRecords([$tokoVoucher])->assertCanNotSeeTableRecords([$gymVoucher]);

        /** Assert direct edit URLs targeting Gym Sehat record IDs under Toko Maju return 404 Not Found. */
        $this->get('/app/'.$tokoMaju->slug.'/branches/'.$gymBranch->getKey().'/edit')->assertNotFound();
        $this->get('/app/'.$tokoMaju->slug.'/contacts/'.$gymContact->getKey().'/edit')->assertNotFound();
        $this->get('/app/'.$tokoMaju->slug.'/categories/'.$gymCategory->getKey().'/edit')->assertNotFound();
        $this->get('/app/'.$tokoMaju->slug.'/products/'.$gymProduct->getKey().'/edit')->assertNotFound();
        $this->get('/app/'.$tokoMaju->slug.'/vouchers/'.$gymVoucher->getKey().'/edit')->assertNotFound();
        $this->get('/app/'.$gymSehat->slug.'/products')->assertNotFound();
    }
}
