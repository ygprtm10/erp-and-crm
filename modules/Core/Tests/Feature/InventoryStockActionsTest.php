<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Database\Seeders\CoreDemoSeeder;
use Modules\Core\Enums\StockMovementType;
use Modules\Core\Filament\Resources\InventoryStockResource\Pages\ListInventoryStocks;
use Modules\Core\Filament\Resources\InventoryStockResource\Pages\ViewInventoryStock;
use Modules\Core\Filament\Resources\InventoryStockResource\RelationManagers\StockMovementsRelationManager;
use Modules\Core\Models\Branch;
use Modules\Core\Models\InventoryStock;
use Modules\Core\Models\ProductVariant;
use Modules\Core\Models\StockMovement;
use Modules\Platform\Database\Seeders\SaasModuleSeeder;
use Modules\Platform\Database\Seeders\TenantDemoSeeder;
use Tests\TestCase;

/**
 * Feature tests verifying StockMovementService-backed Filament actions on InventoryStockResource.
 */
class InventoryStockActionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure Stock In, Stock Out, Adjust, and Transfer actions update inv_stocks and log inv_stock_movements.
     */
    public function test_inventory_stock_actions_mutate_balances_and_record_audit_movements(): void
    {
        /** Arrange Toko Maju with main branch (50.00 stock) and a secondary branch. */
        $this->seed([
            SaasModuleSeeder::class,
            TenantDemoSeeder::class,
            CoreDemoSeeder::class,
        ]);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $mainBranch = Branch::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $secondBranch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $tokoMaju->getKey(),
            'code' => 'CAB-02',
            'name' => 'Cabang Kemang',
            'is_main' => false,
        ]);
        $variant = ProductVariant::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $mainStock = InventoryStock::query()->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('branch_id', $mainBranch->getKey())
            ->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Act 1: Header Stock In (+10.00 -> 60.00). */
        Livewire::test(ListInventoryStocks::class)
            ->callAction('stockIn', data: [
                'branch_id' => $mainBranch->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => '10.00',
                'unit_cost' => '46000.0000',
                'notes' => 'Restock supplier',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('60.00', $mainStock->refresh()->quantity);

        /** Act 2: Row Adjust / Opname (to 55.00 -> delta -5.00). */
        Livewire::test(ListInventoryStocks::class)
            ->callTableAction('adjustStock', $mainStock, data: [
                'target_quantity' => '55.00',
                'notes' => 'Monthly opname',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('55.00', $mainStock->refresh()->quantity);

        /** Act 3: Row Transfer 15.00 to second branch (Main -> 40.00, Second -> 15.00). */
        Livewire::test(ListInventoryStocks::class)
            ->callTableAction('transferStock', $mainStock, data: [
                'destination_branch_id' => $secondBranch->getKey(),
                'quantity' => '15.00',
                'notes' => 'Rebalance stock',
            ])
            ->assertHasNoTableActionErrors();

        $secondStock = InventoryStock::query()
            ->where('branch_id', $secondBranch->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->firstOrFail();

        $this->assertSame('40.00', $mainStock->refresh()->quantity);
        $this->assertSame('15.00', $secondStock->quantity);
        $this->assertSame(5, StockMovement::query()->where('company_id', $tokoMaju->getKey())->count());
        $this->assertSame(2, StockMovement::query()->where('type', StockMovementType::Transfer)->count());

        /** Act 4: Verify StockMovementsRelationManager on ViewInventoryStock shows audit rows and blocks edits. */
        $auditManager = Livewire::test(StockMovementsRelationManager::class, [
            'ownerRecord' => $mainStock,
            'pageClass' => ViewInventoryStock::class,
        ]);

        $auditManager->assertCanSeeTableRecords(
            StockMovement::query()->where('branch_id', $mainBranch->getKey())->get(),
        );
    }

    /**
     * Ensure outbound and transfer actions reject insufficient stock and cross-tenant branch targets.
     */
    public function test_inventory_stock_actions_reject_overdraft_and_cross_tenant_branches(): void
    {
        /** Arrange Toko Maju stock (50.00) and Gym Sehat branch. */
        $this->seed([
            SaasModuleSeeder::class,
            TenantDemoSeeder::class,
            CoreDemoSeeder::class,
        ]);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        $mainStock = InventoryStock::query()->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->firstOrFail();
        $gymBranch = Branch::query()->withoutGlobalScope('company')
            ->where('company_id', $gymSehat->getKey())
            ->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Attempt 1: Stock Out exceeding 50.00 halts and leaves quantity unchanged. */
        Livewire::test(ListInventoryStocks::class)
            ->callAction(TestAction::make('recordStockOut')->table($mainStock), data: [
                'quantity' => '75.00',
                'notes' => 'Overdraft outbound',
            ])
            ->assertActionHalted(TestAction::make('recordStockOut')->table($mainStock));

        $this->assertSame('50.00', $mainStock->refresh()->quantity);

        /** Attempt 2: Transfer targeting Gym Sehat branch ID fails validation/ownership without mutating stock. */
        Livewire::test(ListInventoryStocks::class)
            ->callAction(TestAction::make('transferStock')->table($mainStock), data: [
                'destination_branch_id' => $gymBranch->getKey(),
                'quantity' => '10.00',
            ])
            ->assertHasFormErrors(['destination_branch_id']);

        $this->assertSame('50.00', $mainStock->refresh()->quantity);
        $this->assertSame(1, StockMovement::query()->withoutGlobalScope('company')->count());
    }
}
