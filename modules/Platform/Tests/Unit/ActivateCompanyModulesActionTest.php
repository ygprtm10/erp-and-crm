<?php

declare(strict_types=1);

namespace Modules\Platform\Tests\Unit;

use App\Models\Company;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Platform\Actions\ActivateCompanyModulesAction;
use Modules\Platform\Enums\CompanyModuleStatus;
use Modules\Platform\Models\CompanyModule;
use Modules\Platform\Models\SaasBundle;
use Modules\Platform\Models\SaasModule;
use Tests\TestCase;

/**
 * Unit tests verifying module entitlement activation, dependency resolution, and renewal behavior.
 */
class ActivateCompanyModulesActionTest extends TestCase
{
    use RefreshDatabase;

    private ActivateCompanyModulesAction $action;

    /**
     * Resolve ActivateCompanyModulesAction before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(ActivateCompanyModulesAction::class);
    }

    /**
     * Clear any active Filament tenant after each test.
     */
    protected function tearDown(): void
    {
        Filament::setTenant(null);

        parent::tearDown();
    }

    /**
     * Ensure activating a bundle and add-on automatically activates required module dependencies.
     */
    public function test_activates_bundle_modules_and_required_dependencies(): void
    {
        /** Arrange base POS module, dependent QR Order module, Accounting module, and a bundle. */
        $pos = SaasModule::query()->create(['slug' => 'pos', 'name' => 'POS', 'monthly_price' => '99000.0000']);
        $qrOrder = SaasModule::query()->create([
            'slug' => 'qr_order',
            'name' => 'QR Self Order',
            'monthly_price' => '49000.0000',
            'required_modules' => ['pos'],
        ]);
        $accounting = SaasModule::query()->create(['slug' => 'accounting', 'name' => 'Accounting', 'monthly_price' => '149000.0000']);

        $bundle = SaasBundle::query()->create([
            'slug' => 'paket-kasir-lengkap',
            'name' => 'Paket Kasir',
            'monthly_price' => '120000.0000',
            'annual_price' => '1200000.0000',
            'module_ids' => [$qrOrder->getKey()],
            'is_active' => true,
        ]);

        $targetCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        Filament::setTenant($otherCompany, isQuiet: true);

        $expiresAt = now()->addMonth();

        /** Act by activating the bundle plus the Accounting add-on module. */
        $activated = $this->action->execute(
            company: $targetCompany,
            moduleIds: [$accounting->getKey()],
            bundle: $bundle,
            expiresAt: $expiresAt,
            status: CompanyModuleStatus::Active,
            limits: ['max_branches' => 3],
        );

        /** Assert POS dependency, QR Order, and Accounting are all active for the target company. */
        $this->assertCount(3, $activated);
        $this->assertTrue($targetCompany->hasActiveModule('pos'));
        $this->assertTrue($targetCompany->hasActiveModule('qr_order'));
        $this->assertTrue($targetCompany->hasActiveModule('accounting'));
        $this->assertFalse($otherCompany->hasActiveModule('pos'));
    }

    /**
     * Ensure re-activating an existing module updates its status and expiry without duplicating rows.
     */
    public function test_updates_existing_entitlement_without_creating_duplicate_rows(): void
    {
        /** Arrange a company with an expired trial entitlement. */
        $company = Company::factory()->create();
        $module = SaasModule::query()->create(['slug' => 'membership', 'name' => 'Membership', 'monthly_price' => '99000.0000']);

        $this->action->execute(
            company: $company,
            moduleIds: [$module->getKey()],
            expiresAt: now()->subDay(),
            status: CompanyModuleStatus::Trial,
        );

        $this->assertFalse($company->hasActiveModule('membership'));

        /** Act by renewing the module as Active for 30 days. */
        $this->action->execute(
            company: $company,
            moduleIds: [$module->getKey()],
            expiresAt: now()->addDays(30),
            status: CompanyModuleStatus::Active,
        );

        /** Assert only one entitlement row exists and the module is now active. */
        $this->assertSame(1, CompanyModule::query()->withoutGlobalScope('company')->where('company_id', $company->getKey())->count());
        $this->assertTrue($company->hasActiveModule('membership'));
    }

    /**
     * Ensure invalid or empty module inputs fail validation cleanly.
     */
    public function test_throws_exception_for_empty_or_unknown_modules(): void
    {
        /** Arrange a company. */
        $company = Company::factory()->create();

        /** Assert empty selection throws InvalidArgumentException. */
        try {
            $this->action->execute($company, []);
            $this->fail('Expected InvalidArgumentException when no module IDs are supplied.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('At least one module', $exception->getMessage());
        }

        /** Assert nonexistent module ID throws InvalidArgumentException. */
        $this->expectException(InvalidArgumentException::class);
        $this->action->execute($company, [999999]);
    }
}
