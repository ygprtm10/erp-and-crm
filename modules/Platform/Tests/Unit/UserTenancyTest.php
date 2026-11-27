<?php

declare(strict_types=1);

namespace Modules\Platform\Tests\Unit;

use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Platform\Models\CompanyUser;
use Tests\TestCase;

/**
 * Unit tests verifying User panel access, HasTenants resolution, and tenant membership rules.
 */
class UserTenancyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure only super admins can access the admin panel while authenticated users can access the app panel.
     */
    public function test_can_access_panel_enforces_super_admin_for_backoffice(): void
    {
        /** Arrange a super admin, a regular user, and panel instances. */
        $superAdmin = User::factory()->superAdmin()->create();
        $regularUser = User::factory()->create();

        $adminPanel = Filament::getPanel('admin');
        $appPanel = Filament::getPanel('app');
        $unknownPanel = Panel::make()->id('unknown');

        /** Assert admin panel requires super admin, app panel allows both, and unknown panel is denied. */
        $this->assertTrue($superAdmin->canAccessPanel($adminPanel));
        $this->assertTrue($superAdmin->canAccessPanel($appPanel));
        $this->assertFalse($superAdmin->canAccessPanel($unknownPanel));

        $this->assertFalse($regularUser->canAccessPanel($adminPanel));
        $this->assertTrue($regularUser->canAccessPanel($appPanel));
        $this->assertFalse($regularUser->canAccessPanel($unknownPanel));
    }

    /**
     * Ensure getTenants returns only active companies assigned to the user.
     */
    public function test_get_tenants_returns_only_active_assigned_companies(): void
    {
        /** Arrange a user assigned to one active and one inactive company, plus an unrelated company. */
        $user = User::factory()->create();
        $activeCompany = Company::factory()->create();
        $inactiveCompany = Company::factory()->inactive()->create();
        $unrelatedCompany = Company::factory()->create();

        $user->companies()->attach($activeCompany->getKey(), ['is_owner' => true]);
        $user->companies()->attach($inactiveCompany->getKey(), ['is_owner' => false]);

        $appPanel = Filament::getPanel('app');

        /** Act by resolving accessible tenants for the user. */
        $tenants = $user->getTenants($appPanel);

        /** Assert only the active assigned company is returned. */
        $this->assertCount(1, $tenants);
        $this->assertTrue($tenants->first()->is($activeCompany));
        $this->assertFalse($tenants->contains($inactiveCompany));
        $this->assertFalse($tenants->contains($unrelatedCompany));
    }

    /**
     * Ensure canAccessTenant grants access to active member companies and blocks unauthorized or inactive ones.
     */
    public function test_can_access_tenant_validates_membership_active_status_and_model_type(): void
    {
        /** Arrange active, inactive, and unassigned companies alongside a non-Company model. */
        $user = User::factory()->create();
        $memberCompany = Company::factory()->create();
        $inactiveCompany = Company::factory()->inactive()->create();
        $otherCompany = Company::factory()->create();
        $nonTenantModel = User::factory()->create();

        $user->companies()->attach($memberCompany->getKey(), ['is_owner' => true]);
        $user->companies()->attach($inactiveCompany->getKey(), ['is_owner' => false]);

        /** Assert strict tenant access boundaries. */
        $this->assertTrue($user->canAccessTenant($memberCompany));
        $this->assertFalse($user->canAccessTenant($inactiveCompany));
        $this->assertFalse($user->canAccessTenant($otherCompany));
        $this->assertFalse($user->canAccessTenant($nonTenantModel));
    }

    /**
     * Ensure the company_user pivot model exposes expected attributes and boolean casts.
     */
    public function test_companies_relationship_loads_company_user_pivot_metadata(): void
    {
        /** Arrange a user attached to a company with owner, branch, and role metadata. */
        $user = User::factory()->create();
        $company = Company::factory()->create();

        $user->companies()->attach($company->getKey(), [
            'branch_id' => 10,
            'role_id' => 20,
            'is_owner' => true,
        ]);

        /** Act by loading the pivot record. */
        $attachedCompany = $user->companies()->first();

        /** Assert pivot class, values, and boolean cast. */
        $this->assertInstanceOf(CompanyUser::class, $attachedCompany->pivot);
        $this->assertSame(10, $attachedCompany->pivot->branch_id);
        $this->assertSame(20, $attachedCompany->pivot->role_id);
        $this->assertTrue($attachedCompany->pivot->is_owner);
    }
}
