<?php

declare(strict_types=1);

namespace Modules\Platform\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Platform\Models\SaasBundle;
use Modules\Platform\Models\SaasModule;

/**
 * Seeds curated industry subscription bundles idempotently referencing saas_modules.
 */
class SaasBundleSeeder extends Seeder
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

        $bundles = [
            [
                'slug' => 'paket-kasir',
                'name' => 'Paket Kasir',
                'monthly_price' => '179000.0000',
                'annual_price' => '1790000.0000',
                'module_slugs' => ['pos', 'qr_order', 'web'],
            ],
            [
                'slug' => 'paket-pembukuan',
                'name' => 'Paket Pembukuan',
                'monthly_price' => '149000.0000',
                'annual_price' => '1490000.0000',
                'module_slugs' => ['accounting'],
            ],
            [
                'slug' => 'paket-gym',
                'name' => 'Paket Gym',
                'monthly_price' => '349000.0000',
                'annual_price' => '3490000.0000',
                'module_slugs' => ['membership', 'pos', 'iot', 'accounting'],
            ],
            [
                'slug' => 'paket-laundry',
                'name' => 'Paket Laundry',
                'monthly_price' => '329000.0000',
                'annual_price' => '3290000.0000',
                'module_slugs' => ['laundry', 'pos', 'membership', 'accounting'],
            ],
            [
                'slug' => 'paket-all-in-one',
                'name' => 'Paket All-in-One',
                'monthly_price' => '699000.0000',
                'annual_price' => '6990000.0000',
                'module_slugs' => [
                    'accounting',
                    'pos',
                    'qr_order',
                    'hris',
                    'kpi',
                    'iot',
                    'membership',
                    'laundry',
                    'web',
                ],
            ],
        ];

        foreach ($bundles as $bundle) {
            $resolvedModuleIds = collect($bundle['module_slugs'])
                ->map(static fn (string $slug): ?int => $moduleIdsBySlug->get($slug))
                ->filter()
                ->values()
                ->all();

            SaasBundle::query()->updateOrCreate(
                ['slug' => $bundle['slug']],
                [
                    'name' => $bundle['name'],
                    'monthly_price' => $bundle['monthly_price'],
                    'annual_price' => $bundle['annual_price'],
                    'module_ids' => $resolvedModuleIds,
                    'is_active' => true,
                ],
            );
        }
    }
}
