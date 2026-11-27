<?php

declare(strict_types=1);

namespace Modules\Platform\Tests\Unit;

use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Platform\Filament\PlatformAdminPlugin;
use Modules\Platform\Filament\PlatformTenantPlugin;
use Modules\Platform\Models\CompanyUser;
use Tests\TestCase;

/**
 * Unit tests for Company, CompanyUser, Platform migrations, and Platform Filament plugins.
 */
class CompanyAndPluginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure Platform migrations create companies, company_user, and user platform columns.
     */
    public function test_platform_migrations_create_expected_tables_and_columns(): void
    {
        /** Assert all required columns exist in the migrated schema. */
        $this->assertTrue(Schema::hasColumns('companies', [
            'id',
            'name',
            'slug',
            'custom_domain',
            'business_type',
            'is_active',
        ]));

        $this->assertTrue(Schema::hasColumns('company_user', [
            'id',
            'company_id',
            'user_id',
            'branch_id',
            'role_id',
            'is_owner',
        ]));

        $this->assertTrue(Schema::hasColumns('users', [
            'phone',
            'is_super_admin',
        ]));
    }

    /**
     * Ensure Company exposes Filament display name, users relation, boolean casts, and module check.
     */
    public function test_company_model_exposes_tenancy_contract_and_relations(): void
    {
        /** Arrange a company with an attached user. */
        $company = Company::factory()->create([
            'name' => 'PT Maju Jaya',
            'is_active' => 1,
        ]);
        $user = User::factory()->create();
        $company->users()->attach($user->getKey(), ['is_owner' => true]);

        /** Act and assert Filament name, cast, relation, and Stage 1 module check. */
        $this->assertSame('PT Maju Jaya', $company->getFilamentName());
        $this->assertIsBool($company->is_active);
        $this->assertTrue($company->is_active);
        $this->assertCount(1, $company->users);
        $this->assertFalse($company->hasActiveModule('accounting'));
    }

    /**
     * Ensure CompanyUser pivot model resolves its company and user relations.
     */
    public function test_company_user_pivot_resolves_company_and_user_relations(): void
    {
        /** Arrange a company-user membership record. */
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $company->users()->attach($user->getKey(), ['is_owner' => false]);

        /** Act by querying the pivot model directly. */
        $pivot = CompanyUser::query()->firstOrFail();

        /** Assert pivot casts and BelongsTo relations. */
        $this->assertFalse($pivot->is_owner);
        $this->assertTrue($pivot->company->is($company));
        $this->assertTrue($pivot->user->is($user));
    }

    /**
     * Ensure both Platform Filament plugins are registered on their respective panels.
     */
    public function test_platform_plugins_are_registered_on_dual_panels(): void
    {
        /** Arrange both panels from Filament registry. */
        $adminPanel = Filament::getPanel('admin');
        $appPanel = Filament::getPanel('app');

        /** Assert plugin identifiers and panel wiring. */
        $this->assertSame('platform-admin', PlatformAdminPlugin::make()->getId());
        $this->assertSame('platform-tenant', PlatformTenantPlugin::make()->getId());

        $this->assertTrue($adminPanel->hasPlugin('platform-admin'));
        $this->assertFalse($adminPanel->hasTenancy());
        $this->assertSame('backoffice', $adminPanel->getPath());

        $this->assertTrue($appPanel->hasPlugin('platform-tenant'));
        $this->assertTrue($appPanel->hasTenancy());
        $this->assertSame('app', $appPanel->getPath());
        $this->assertSame(Company::class, $appPanel->getTenantModel());
        $this->assertSame('slug', $appPanel->getTenantSlugAttribute());
    }
}
