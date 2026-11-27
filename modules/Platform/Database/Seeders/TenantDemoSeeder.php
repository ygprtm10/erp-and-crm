<?php

declare(strict_types=1);

namespace Modules\Platform\Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Platform\Actions\ActivateCompanyModulesAction;
use Modules\Platform\Enums\CompanyModuleStatus;
use Modules\Platform\Enums\PaymentGatewayMode;
use Modules\Platform\Events\CompanyRegistered;
use Modules\Platform\Models\CompanySetting;
use Modules\Platform\Models\SaasModule;

/**
 * Seeds isolated demo tenant accounts, companies, settings, wallets, and modules for local/testing verification.
 */
class TenantDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /** Ensure module catalog exists if this seeder is executed standalone. */
        if (SaasModule::query()->count() === 0) {
            $this->call(SaasModuleSeeder::class);
        }

        $moduleIdsBySlug = SaasModule::query()->pluck('id', 'slug');

        $demoTenants = [
            [
                'owner' => [
                    'name' => 'Owner Toko Maju',
                    'email' => 'owner@test.com',
                    'phone' => '081200000001',
                ],
                'company' => [
                    'name' => 'Toko Maju',
                    'slug' => 'toko-maju',
                    'business_type' => 'retail',
                ],
                'settings' => [
                    'payment_gateway_mode' => PaymentGatewayMode::PlatformMidtrans,
                    'midtrans_is_production' => false,
                    'midtrans_merchant_id' => null,
                    'midtrans_server_key' => null,
                    'midtrans_client_key' => null,
                ],
                'module_slugs' => ['pos', 'accounting'],
            ],
            [
                'owner' => [
                    'name' => 'Owner Gym Sehat',
                    'email' => 'owner2@test.com',
                    'phone' => '081200000002',
                ],
                'company' => [
                    'name' => 'Gym Sehat',
                    'slug' => 'gym-sehat',
                    'business_type' => 'gym',
                ],
                'settings' => [
                    'payment_gateway_mode' => PaymentGatewayMode::OwnMidtrans,
                    'midtrans_is_production' => false,
                    'midtrans_merchant_id' => 'DEMO-GYM-M01',
                    'midtrans_server_key' => 'SB-Mid-server-demo-gym-sehat',
                    'midtrans_client_key' => 'SB-Mid-client-demo-gym-sehat',
                ],
                'module_slugs' => ['membership'],
            ],
        ];

        $activateModules = app(ActivateCompanyModulesAction::class);

        foreach ($demoTenants as $tenantData) {
            $this->seedTenant($tenantData, $moduleIdsBySlug->all(), $activateModules);
        }
    }

    /**
     * Idempotently create or update one demo tenant owner, company, defaults, settings, and module entitlements.
     *
     * @param  array{
     *     owner: array{name: string, email: string, phone: string},
     *     company: array{name: string, slug: string, business_type: string},
     *     settings: array<string, mixed>,
     *     module_slugs: array<int, string>
     * }  $tenantData
     * @param  array<string, int>  $moduleIdsBySlug
     */
    private function seedTenant(
        array $tenantData,
        array $moduleIdsBySlug,
        ActivateCompanyModulesAction $activateModules,
    ): void {
        /** Create or update the tenant owner without granting Super Admin privileges. */
        $user = User::query()->firstOrNew(['email' => $tenantData['owner']['email']]);
        $user->name = $tenantData['owner']['name'];
        $user->phone = $tenantData['owner']['phone'];
        $user->email_verified_at ??= now();
        $user->password = Hash::make('password');
        $user->is_super_admin = false;
        $user->save();

        /** Create or update the tenant Company record. */
        $company = Company::query()->updateOrCreate(
            ['slug' => $tenantData['company']['slug']],
            [
                'name' => $tenantData['company']['name'],
                'business_type' => $tenantData['company']['business_type'],
                'is_active' => true,
            ],
        );

        /** Attach the owner to the company via company_user idempotently. */
        $company->users()->syncWithoutDetaching([
            $user->getKey() => ['is_owner' => true],
        ]);

        /** Provision default zero-balance wallet and initial settings via Platform registration event. */
        CompanyRegistered::dispatch($company);

        /** Enforce the target payment gateway configuration idempotently. */
        CompanySetting::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $company->getKey()],
                $tenantData['settings'],
            );

        $moduleIds = array_values(array_filter(array_map(
            static fn (string $slug): ?int => $moduleIdsBySlug[$slug] ?? null,
            $tenantData['module_slugs'],
        )));

        $activateModules->execute(
            company: $company,
            moduleIds: $moduleIds,
            expiresAt: now()->addYear(),
            status: CompanyModuleStatus::Active,
        );
    }
}
