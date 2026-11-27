<?php

declare(strict_types=1);

namespace Modules\Platform\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Platform\Models\SaasModule;

/**
 * Seeds the global SaaS module catalog idempotently with pricing and module dependencies.
 */
class SaasModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $modules = [
            [
                'slug' => 'accounting',
                'name' => 'Accounting & Double-Entry',
                'monthly_price' => '149000.0000',
                'required_modules' => null,
            ],
            [
                'slug' => 'pos',
                'name' => 'Point of Sale (POS)',
                'monthly_price' => '99000.0000',
                'required_modules' => null,
            ],
            [
                'slug' => 'qr_order',
                'name' => 'QR Self-Order & KDS',
                'monthly_price' => '59000.0000',
                'required_modules' => ['pos'],
            ],
            [
                'slug' => 'hris',
                'name' => 'HRIS & Payroll',
                'monthly_price' => '129000.0000',
                'required_modules' => null,
            ],
            [
                'slug' => 'kpi',
                'name' => 'KPI Performance Tracking',
                'monthly_price' => '79000.0000',
                'required_modules' => ['hris'],
            ],
            [
                'slug' => 'iot',
                'name' => 'IoT Hardware Integration (ESP32)',
                'monthly_price' => '99000.0000',
                'required_modules' => null,
            ],
            [
                'slug' => 'membership',
                'name' => 'Membership & Quota',
                'monthly_price' => '99000.0000',
                'required_modules' => null,
            ],
            [
                'slug' => 'laundry',
                'name' => 'Laundry Stages & Tracking',
                'monthly_price' => '99000.0000',
                'required_modules' => null,
            ],
            [
                'slug' => 'web',
                'name' => 'Company Web & Public Catalog',
                'monthly_price' => '49000.0000',
                'required_modules' => null,
            ],
        ];

        foreach ($modules as $module) {
            SaasModule::query()->updateOrCreate(
                ['slug' => $module['slug']],
                [
                    'name' => $module['name'],
                    'monthly_price' => $module['monthly_price'],
                    'required_modules' => $module['required_modules'],
                ],
            );
        }
    }
}
