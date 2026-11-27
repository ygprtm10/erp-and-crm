<?php

declare(strict_types=1);

namespace Modules\Platform\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Platform\Models\SaasBundle;
use Modules\Platform\Models\SaasModule;
use Modules\Platform\Models\SaasTenantBankAccount;
use Modules\Platform\Services\WalletLedgerService;
use Tests\TestCase;

/**
 * Feature tests verifying Super Admin Filament 5 resource routes and authorization boundaries.
 */
class AdminResourcesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure Super Admins can access Company, SaasModule, SaasBundle, and SaasWithdrawal pages.
     */
    public function test_super_admin_can_render_platform_admin_resources(): void
    {
        /** Arrange a Super Admin, tenant, module, bundle, and pending withdrawal. */
        $superAdmin = User::factory()->superAdmin()->create();
        $tenantUser = User::factory()->create();
        $company = Company::factory()->create();
        $module = SaasModule::query()->create(['slug' => 'pos', 'name' => 'POS', 'monthly_price' => '99000.0000']);
        $bundle = SaasBundle::query()->create([
            'slug' => 'kasir',
            'name' => 'Paket Kasir',
            'monthly_price' => '99000.0000',
            'annual_price' => '990000.0000',
            'module_ids' => [$module->getKey()],
            'is_active' => true,
        ]);

        $bankAccount = SaasTenantBankAccount::query()->create([
            'company_id' => $company->getKey(),
            'bank_code' => '014',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'Owner',
        ]);

        $walletService = app(WalletLedgerService::class);
        $walletService->creditSale($company, '300000.0000', 'trx_payments', 1);
        $withdrawal = $walletService->requestWithdrawal($company, $bankAccount, $tenantUser, '100000.0000');

        /** Act and assert Super Admin receives 200 OK across all Platform backoffice resource pages. */
        $this->actingAs($superAdmin)
            ->get('/backoffice/companies')
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get('/backoffice/companies/create')
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get('/backoffice/companies/'.$company->getKey().'/edit')
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get('/backoffice/saas-modules')
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get('/backoffice/saas-bundles')
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get('/backoffice/saas-bundles/'.$bundle->getKey().'/edit')
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get('/backoffice/saas-withdrawals')
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get('/backoffice/saas-withdrawals/'.$withdrawal->getKey())
            ->assertOk();
    }

    /**
     * Ensure non-super-admin users receive 403 Forbidden on Super Admin Platform resources.
     */
    public function test_regular_user_is_forbidden_from_platform_admin_resources(): void
    {
        /** Arrange a regular non-super-admin user. */
        $regularUser = User::factory()->create();

        /** Assert 403 Forbidden on backoffice resource endpoints. */
        $this->actingAs($regularUser)->get('/backoffice/companies')->assertForbidden();
        $this->actingAs($regularUser)->get('/backoffice/saas-modules')->assertForbidden();
        $this->actingAs($regularUser)->get('/backoffice/saas-bundles')->assertForbidden();
        $this->actingAs($regularUser)->get('/backoffice/saas-withdrawals')->assertForbidden();
    }
}
