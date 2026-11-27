<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Database\Seeders\CoreDemoSeeder;
use Modules\Platform\Database\Seeders\SaasBundleSeeder;
use Modules\Platform\Database\Seeders\SaasModuleSeeder;
use Modules\Platform\Database\Seeders\SuperAdminSeeder;
use Modules\Platform\Database\Seeders\TenantDemoSeeder;

/**
 * Root database seeder orchestrating global Platform catalogs and local demo tenants.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        /** Always seed core Super Admin and SaaS module/bundle catalogs across all environments. */
        $this->call([
            SuperAdminSeeder::class,
            SaasModuleSeeder::class,
            SaasBundleSeeder::class,
        ]);

        /** Seed demo multi-tenant accounts and Core sample data strictly in local/testing environments. */
        if (app()->environment(['local', 'testing'])) {
            $this->call([
                TenantDemoSeeder::class,
                CoreDemoSeeder::class,
            ]);
        }
    }
}
