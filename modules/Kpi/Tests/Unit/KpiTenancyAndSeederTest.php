<?php

declare(strict_types=1);

namespace Modules\Kpi\Tests\Unit;

use App\Models\Company;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Hris\Models\Employee;
use Modules\Kpi\Enums\KpiEvaluationStatus;
use Modules\Kpi\Enums\KpiMetricType;
use Modules\Kpi\Enums\KpiPeriodType;
use Modules\Kpi\Models\KpiBonusTier;
use Modules\Kpi\Models\KpiEvaluation;
use Modules\Kpi\Models\KpiEvaluationItem;
use Modules\Kpi\Models\KpiIndicator;
use Modules\Kpi\Models\KpiPeriod;
use Modules\Transaction\Models\Order;
use Tests\TestCase;

/**
 * Unit tests verifying BelongsToCompany tenant isolation and KpiDemoSeeder idempotency.
 */
class KpiTenancyAndSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure BelongsToCompany strictly scopes all KPI models to the active Filament tenant.
     */
    public function test_belongs_to_company_isolates_all_kpi_models_per_tenant(): void
    {
        /** Arrange KPI records in two separate companies. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $indicatorA = KpiIndicator::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyA->getKey(),
            'code' => 'IND-A',
            'name' => 'Indicator A',
            'metric_type' => KpiMetricType::ManualNumeric,
            'unit' => 'pcs',
            'default_weight' => '100.00',
            'default_target' => '10.0000',
        ]);
        $indicatorB = KpiIndicator::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyB->getKey(),
            'code' => 'IND-B',
            'name' => 'Indicator B',
            'metric_type' => KpiMetricType::ManualNumeric,
            'unit' => 'pcs',
            'default_weight' => '100.00',
            'default_target' => '10.0000',
        ]);

        $periodA = KpiPeriod::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyA->getKey(),
            'name' => 'Period A',
            'period_type' => KpiPeriodType::Monthly,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
        ]);
        KpiPeriod::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyB->getKey(),
            'name' => 'Period B',
            'period_type' => KpiPeriodType::Monthly,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
        ]);

        /** Act by setting active Filament tenant to Company A. */
        Filament::setTenant($companyA, isQuiet: true);

        try {
            $this->assertSame([$indicatorA->getKey()], KpiIndicator::query()->pluck('id')->all());
            $this->assertNull(KpiIndicator::query()->find($indicatorB->getKey()));
            $this->assertSame([$periodA->getKey()], KpiPeriod::query()->pluck('id')->all());
        } finally {
            Filament::setTenant(null, isQuiet: true);
        }
    }

    /**
     * Ensure DatabaseSeeder runs KpiDemoSeeder idempotently, gates kpi on Toko Maju only, and links paid orders.
     */
    public function test_database_seeder_runs_kpi_demo_idempotently_and_enforces_module_gating(): void
    {
        /** Act by executing full DatabaseSeeder twice consecutively. */
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        /** Assert KPI module is active only on Toko Maju. */
        $this->assertTrue($tokoMaju->hasActiveModule('kpi'));
        $this->assertFalse($gymSehat->hasActiveModule('kpi'));

        /** Assert zero duplicates for bonus tiers, indicators, periods, and evaluations. */
        $this->assertSame(4, KpiBonusTier::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->count());

        $indicators = KpiIndicator::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->get();
        $this->assertCount(4, $indicators);
        $totalWeight = $indicators->reduce(
            static fn (string $carry, KpiIndicator $ind): string => bcadd($carry, (string) $ind->default_weight, 2),
            '0.00',
        );
        $this->assertSame('100.00', $totalWeight);

        $this->assertSame(1, KpiPeriod::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->count());

        $empOne = Employee::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('employee_code', 'EMP-001')
            ->firstOrFail();

        /** Assert seeded paid order TRX-SEED-001 was linked to EMP-001. */
        $seedOrder = Order::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('order_number', 'TRX-SEED-001')
            ->firstOrFail();
        $this->assertSame($empOne->getKey(), $seedOrder->salesperson_employee_id);

        /** Assert EMP-001 evaluation is approved with non-zero sales_revenue actuals and bonus computed. */
        $empOneEval = KpiEvaluation::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('employee_id', $empOne->getKey())
            ->firstOrFail();
        $this->assertSame(KpiEvaluationStatus::Approved, $empOneEval->status);
        $this->assertSame('100.00', $empOneEval->total_weight);
        $this->assertNotNull($empOneEval->grade);

        $salesItem = KpiEvaluationItem::query()
            ->withoutGlobalScope('company')
            ->where('kpi_evaluation_id', $empOneEval->getKey())
            ->where('metric_type_snapshot', KpiMetricType::SalesRevenue->value)
            ->firstOrFail();
        $this->assertTrue($salesItem->is_auto_synced);
        $this->assertSame(1, bccomp((string) $salesItem->actual_value, '0.0000', 4));
    }

    /**
     * Ensure KpiDemoSeeder is skipped in production environment.
     */
    public function test_kpi_demo_seeder_is_skipped_in_production_environment(): void
    {
        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
        } finally {
            $this->app['env'] = $originalEnv;
        }

        $this->assertSame(0, KpiIndicator::query()->withoutGlobalScope('company')->count());
        $this->assertSame(0, KpiPeriod::query()->withoutGlobalScope('company')->count());
        $this->assertSame(0, KpiBonusTier::query()->withoutGlobalScope('company')->count());
        $this->assertSame(0, KpiEvaluation::query()->withoutGlobalScope('company')->count());
    }
}
