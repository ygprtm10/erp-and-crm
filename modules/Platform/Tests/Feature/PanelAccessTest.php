<?php

declare(strict_types=1);

namespace Modules\Platform\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests verifying dual Filament 5 panel routing, authentication, and tenant isolation.
 */
class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure login endpoints for both panels are reachable and unauthenticated panel requests redirect.
     */
    public function test_panel_login_pages_load_and_guests_are_redirected(): void
    {
        /** Arrange a tenant company for URL verification. */
        $company = Company::factory()->create(['slug' => 'toko-sejahtera']);

        /** Assert login pages render cleanly for both panels. */
        $this->get('/backoffice/login')->assertOk();
        $this->get('/app/login')->assertOk();

        /** Assert unauthenticated visitors are redirected to each panel's login route. */
        $this->get('/backoffice')->assertRedirect('/backoffice/login');
        $this->get('/app/'.$company->slug)->assertRedirect('/app/login');
    }

    /**
     * Ensure only Super Admins can access /backoffice while regular users receive 403 Forbidden.
     */
    public function test_backoffice_panel_restricts_access_to_super_admins(): void
    {
        /** Arrange a regular user and a Super Admin user. */
        $regularUser = User::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        /** Assert regular user is denied with 403 and Super Admin receives 200 OK. */
        $this->actingAs($regularUser)
            ->get('/backoffice')
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->get('/backoffice')
            ->assertOk();
    }

    /**
     * Ensure tenant panel allows member access to active companies and blocks cross-tenant or inactive access.
     */
    public function test_app_panel_enforces_multi_tenant_isolation_by_slug(): void
    {
        /** Arrange two active companies, one inactive company, and a user assigned to Company A and Inactive C. */
        $user = User::factory()->create();
        $companyA = Company::factory()->create(['slug' => 'berkah-laundry']);
        $companyB = Company::factory()->create(['slug' => 'jaya-gym']);
        $companyC = Company::factory()->inactive()->create(['slug' => 'nonaktif-store']);

        $user->companies()->attach($companyA->getKey(), ['is_owner' => true]);
        $user->companies()->attach($companyC->getKey(), ['is_owner' => true]);

        /** Act and assert authorized active tenant succeeds while unauthorized and inactive tenants return 404. */
        $this->actingAs($user)
            ->get('/app/'.$companyA->slug)
            ->assertOk();

        $this->actingAs($user)
            ->get('/app/'.$companyB->slug)
            ->assertNotFound();

        $this->actingAs($user)
            ->get('/app/'.$companyC->slug)
            ->assertNotFound();
    }
}
