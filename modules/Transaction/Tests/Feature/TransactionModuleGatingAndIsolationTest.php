<?php

declare(strict_types=1);

namespace Modules\Transaction\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\Branch;
use Modules\Core\Models\ProductVariant;
use Modules\Platform\Actions\ActivateCompanyModulesAction;
use Modules\Platform\Models\SaasModule;
use Modules\Transaction\Enums\OrderChannel;
use Modules\Transaction\Filament\Pages\PosCashierPage;
use Modules\Transaction\Filament\Resources\OrderResource;
use Modules\Transaction\Filament\Resources\OrderResource\Pages\ListOrders;
use Modules\Transaction\Filament\Resources\PosShiftResource;
use Modules\Transaction\Filament\Resources\PosTableResource;
use Modules\Transaction\Filament\Resources\PosTableResource\Pages\CreatePosTable;
use Modules\Transaction\Filament\TransactionPlugin;
use Modules\Transaction\Models\Order;
use Modules\Transaction\Models\PosTable;
use Modules\Transaction\Services\OrderCreationService;
use Tests\TestCase;

/**
 * Feature tests verifying TransactionPlugin registration, RequiresModule gating, and cross-tenant isolation.
 */
class TransactionModuleGatingAndIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure OrderResource strictly isolates orders between Toko Maju and Gym Sehat.
     */
    public function test_order_resource_enforces_cross_tenant_isolation(): void
    {
        /** Arrange seeded Toko Maju (with TRX-SEED-001) and create an isolated order on Gym Sehat. */
        $this->seed(DatabaseSeeder::class);

        $appPanel = Filament::getPanel('app');
        $this->assertTrue($appPanel->hasPlugin('transaction'));
        $this->assertSame('transaction', TransactionPlugin::make()->getId());

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $owner2 = User::query()->where('email', 'owner2@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        $gymBranch = Branch::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->firstOrFail();
        $gymVariant = ProductVariant::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->where('sku', 'GYM-MONTHLY-01')->firstOrFail();

        $gymOrder = app(OrderCreationService::class)->createOrder(
            company: $gymSehat,
            branch: $gymBranch,
            channel: OrderChannel::Membership,
            items: [['product_variant_id' => $gymVariant->getKey(), 'qty' => '1.00']],
            orderNumber: 'TRX-GYM-01',
        );

        $tokoOrder = Order::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();

        /** Assert Toko Maju sees only its own order and gets 404 when targeting Gym Sehat order URL. */
        $this->actingAs($owner1);
        Filament::setCurrentPanel($appPanel);
        Filament::setTenant($tokoMaju, isQuiet: true);

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$tokoOrder])
            ->assertCanNotSeeTableRecords([$gymOrder]);

        $this->get('/app/'.$tokoMaju->slug.'/orders/'.$gymOrder->getKey())->assertNotFound();
        $this->flushSession();

        /** Assert Gym Sehat sees only its own order and gets 404 when targeting Toko Maju order URL. */
        $this->actingAs($owner2);
        Filament::setTenant($gymSehat, isQuiet: true);

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$gymOrder])
            ->assertCanNotSeeTableRecords([$tokoOrder]);

        $this->get('/app/'.$gymSehat->slug.'/orders/'.$tokoOrder->getKey())->assertNotFound();
    }

    /**
     * Ensure RequiresModule enforces pos and qr_order entitlements on PosShift, PosCashier, and PosTable.
     */
    public function test_requires_module_gating_for_pos_and_qr_order_resources(): void
    {
        /** Arrange seeded Toko Maju (pos active, qr_order inactive) and Gym Sehat (both inactive). */
        $this->seed(DatabaseSeeder::class);

        $appPanel = Filament::getPanel('app');
        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $owner2 = User::query()->where('email', 'owner2@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        /** Assert Toko Maju accesses Order, PosShift, and PosCashierPage, while PosTable returns 403. */
        $this->actingAs($owner1);
        Filament::setCurrentPanel($appPanel);
        Filament::setTenant($tokoMaju, isQuiet: true);

        $this->assertTrue(OrderResource::canAccess());
        $this->assertTrue(PosShiftResource::canAccess());
        $this->assertTrue(PosCashierPage::canAccess());
        $this->assertFalse(PosTableResource::canAccess());

        $this->get(OrderResource::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(PosShiftResource::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(PosCashierPage::getUrl(['tenant' => $tokoMaju]))->assertOk();
        $this->get(PosTableResource::getUrl('index', ['tenant' => $tokoMaju]))->assertForbidden();

        /** Activate qr_order module for Toko Maju and verify PosTableResource becomes accessible and creates QR table. */
        $qrModule = SaasModule::query()->where('slug', 'qr_order')->firstOrFail();
        app(ActivateCompanyModulesAction::class)->execute($tokoMaju, [$qrModule->getKey()]);

        $this->assertTrue(PosTableResource::canAccess());
        $this->get(PosTableResource::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();

        $tokoBranch = Branch::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        Livewire::test(CreatePosTable::class)
            ->fillForm([
                'branch_id' => $tokoBranch->getKey(),
                'table_number' => 'T-02',
                'capacity' => 6,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(PosTable::query()->where('table_number', 'T-02')->exists());
        $this->flushSession();

        /** Assert Gym Sehat accesses OrderResource, while PosShift, PosCashierPage, and PosTable return 403. */
        $this->actingAs($owner2);
        Filament::setTenant($gymSehat, isQuiet: true);

        $this->assertTrue(OrderResource::canAccess());
        $this->assertFalse(PosShiftResource::canAccess());
        $this->assertFalse(PosCashierPage::canAccess());
        $this->assertFalse(PosTableResource::canAccess());

        $this->get(OrderResource::getUrl('index', ['tenant' => $gymSehat]))->assertOk();
        $this->get(PosShiftResource::getUrl('index', ['tenant' => $gymSehat]))->assertForbidden();
        $this->get(PosCashierPage::getUrl(['tenant' => $gymSehat]))->assertForbidden();
        $this->get(PosTableResource::getUrl('index', ['tenant' => $gymSehat]))->assertForbidden();
    }
}
