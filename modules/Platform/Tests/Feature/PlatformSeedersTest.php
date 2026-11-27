<?php

declare(strict_types=1);

namespace Modules\Platform\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Platform\Enums\PaymentGatewayMode;
use Modules\Platform\Models\CompanyModule;
use Modules\Platform\Models\CompanySetting;
use Modules\Platform\Models\SaasBundle;
use Modules\Platform\Models\SaasModule;
use Modules\Platform\Models\SaasTenantWallet;
use Tests\TestCase;

/**
 * Feature tests verifying Platform seeders, idempotency, environment gating, and dual-panel isolation.
 */
class PlatformSeedersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure DatabaseSeeder populates Super Admin, modules, bundles, and demo tenants with complete relations.
     */
    public function test_database_seeder_creates_complete_platform_catalog_and_demo_tenants(): void
    {
        /** Act by executing the root DatabaseSeeder. */
        $this->seed(DatabaseSeeder::class);

        /** Assert Super Admin, 9 modules, and 5 bundles exist with expected attributes. */
        $admin = User::query()->where('email', 'admin@test.com')->firstOrFail();
        $this->assertTrue($admin->is_super_admin);
        $this->assertTrue(Hash::check('password', $admin->password));

        $this->assertSame(9, SaasModule::query()->count());
        $qrOrder = SaasModule::query()->where('slug', 'qr_order')->firstOrFail();
        $this->assertSame(['pos'], $qrOrder->required_modules);

        $this->assertSame(5, SaasBundle::query()->count());
        $allInOne = SaasBundle::query()->where('slug', 'paket-all-in-one')->firstOrFail();
        $this->assertCount(9, $allInOne->module_ids);

        /** Assert Toko Maju and Gym Sehat have settings, zero-balance wallets, and active modules. */
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $tokoSettings = CompanySetting::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $tokoWallet = SaasTenantWallet::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();

        $this->assertSame(PaymentGatewayMode::PlatformMidtrans, $tokoSettings->payment_gateway_mode);
        $this->assertSame('0.0000', $tokoWallet->available_balance);
        $this->assertTrue($tokoMaju->hasActiveModule('pos'));
        $this->assertTrue($tokoMaju->hasActiveModule('accounting'));
        $this->assertTrue($tokoMaju->hasActiveModule('hris'));
        $this->assertTrue($tokoMaju->hasActiveModule('iot'));
        $this->assertTrue($tokoMaju->hasActiveModule('kpi'));
        $this->assertFalse($tokoMaju->hasActiveModule('membership'));

        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();
        $gymSettings = CompanySetting::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->firstOrFail();
        $gymWallet = SaasTenantWallet::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->firstOrFail();

        $this->assertSame(PaymentGatewayMode::OwnMidtrans, $gymSettings->payment_gateway_mode);
        $this->assertSame('0.0000', $gymWallet->available_balance);
        $this->assertTrue($gymSehat->hasActiveModule('membership'));
        $this->assertFalse($gymSehat->hasActiveModule('pos'));
        $this->assertFalse($gymSehat->hasActiveModule('hris'));
        $this->assertFalse($gymSehat->hasActiveModule('iot'));
        $this->assertFalse($gymSehat->hasActiveModule('kpi'));
    }

    /**
     * Ensure running all Platform seeders twice in succession creates zero duplicate rows.
     */
    public function test_database_seeder_is_idempotent_when_executed_multiple_times(): void
    {
        /** Act by executing the full database seeder twice consecutively. */
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        /** Assert row counts remain exact with no duplicates. */
        $this->assertSame(3, User::query()->count());
        $this->assertSame(2, Company::query()->count());
        $this->assertSame(9, SaasModule::query()->count());
        $this->assertSame(5, SaasBundle::query()->count());
        $this->assertSame(2, CompanySetting::query()->withoutGlobalScope('company')->count());
        $this->assertSame(2, SaasTenantWallet::query()->withoutGlobalScope('company')->count());
        $this->assertSame(6, CompanyModule::query()->withoutGlobalScope('company')->count());
    }

    /**
     * Ensure TenantDemoSeeder is skipped when running outside local and testing environments.
     */
    public function test_database_seeder_skips_demo_tenants_in_production_environment(): void
    {
        /** Arrange simulated production environment. */
        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            /** Act by running DatabaseSeeder in production mode with --force. */
            $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
        } finally {
            $this->app['env'] = $originalEnv;
        }

        /** Assert Super Admin and global catalogs are seeded while demo tenants are excluded. */
        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Company::query()->count());
        $this->assertSame(9, SaasModule::query()->count());
        $this->assertSame(5, SaasBundle::query()->count());
    }

    /**
     * Ensure seeded accounts respect /backoffice authorization and strict /app tenant isolation.
     */
    public function test_seeded_users_enforce_backoffice_and_tenant_panel_isolation(): void
    {
        /** Arrange seeded users and companies. */
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@test.com')->firstOrFail();
        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $owner2 = User::query()->where('email', 'owner2@test.com')->firstOrFail();

        $appPanel = Filament::getPanel('app');

        /** Assert tenant lists are strictly isolated per owner. */
        $this->assertSame(['toko-maju'], $owner1->getTenants($appPanel)->pluck('slug')->all());
        $this->assertSame(['gym-sehat'], $owner2->getTenants($appPanel)->pluck('slug')->all());

        /** Assert Super Admin accesses /backoffice. */
        $this->actingAs($admin)->get('/backoffice')->assertOk();
        $this->flushSession();

        /** Assert owner1 is forbidden from /backoffice and only accesses /app/toko-maju. */
        $this->actingAs($owner1)->get('/backoffice')->assertForbidden();
        $this->actingAs($owner1)->get('/app/toko-maju')->assertOk();
        $this->actingAs($owner1)->get('/app/gym-sehat')->assertNotFound();
        $this->flushSession();

        /** Assert owner2 is forbidden from /backoffice and only accesses /app/gym-sehat. */
        $this->actingAs($owner2)->get('/backoffice')->assertForbidden();
        $this->actingAs($owner2)->get('/app/gym-sehat')->assertOk();
        $this->actingAs($owner2)->get('/app/toko-maju')->assertNotFound();
    }
}
