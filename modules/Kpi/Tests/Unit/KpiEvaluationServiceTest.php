<?php

declare(strict_types=1);

namespace Modules\Kpi\Tests\Unit;

use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Hris\Enums\AttendanceSource;
use Modules\Hris\Enums\AttendanceStatus;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Department;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\Position;
use Modules\Kpi\Enums\KpiBonusType;
use Modules\Kpi\Enums\KpiEvaluationStatus;
use Modules\Kpi\Enums\KpiMetricType;
use Modules\Kpi\Enums\KpiPeriodStatus;
use Modules\Kpi\Enums\KpiPeriodType;
use Modules\Kpi\Models\KpiBonusTier;
use Modules\Kpi\Models\KpiEvaluation;
use Modules\Kpi\Models\KpiEvaluationItem;
use Modules\Kpi\Models\KpiIndicator;
use Modules\Kpi\Models\KpiPeriod;
use Modules\Kpi\Services\KpiEvaluationService;
use Tests\TestCase;

/**
 * Unit tests verifying KpiEvaluationService scoring, weight validation, caps, and immutability guards.
 */
class KpiEvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure achievements cap at 120%, zero targets return 0%, lower-is-better calculates properly, and weights sum cleanly.
     */
    public function test_calculates_achievements_caps_zero_target_lower_is_better_and_weighted_scores(): void
    {
        /** Arrange tenant, bonus tier, employee, period, and mixed indicators (100% total weight). */
        $company = Company::factory()->create();
        $employee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'employee_code' => 'EMP-KPI-01',
            'full_name' => 'Rina KPI',
            'base_salary' => '10000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        KpiBonusTier::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'min_score' => '95.00',
            'max_score' => '120.00',
            'grade' => 'A',
            'bonus_type' => KpiBonusType::PercentageOfBaseSalary,
            'bonus_value' => '10.0000',
            'is_active' => true,
        ]);

        /** Attendance indicator (higher is better, weight 40%, actual 100% vs target 50% -> capped at 120.00%). */
        KpiIndicator::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'IND-ATT',
            'name' => 'Attendance',
            'metric_type' => KpiMetricType::AttendanceRate,
            'unit' => '%',
            'default_weight' => '40.00',
            'default_target' => '50.0000',
            'is_higher_better' => true,
            'is_active' => true,
        ]);

        /** Error count indicator (lower is better, weight 40%, target 5 vs actual 10 -> 50.00%). */
        $lowerIsBetterInd = KpiIndicator::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'IND-ERR',
            'name' => 'Defect Count',
            'metric_type' => KpiMetricType::ManualNumeric,
            'unit' => 'pcs',
            'default_weight' => '40.00',
            'default_target' => '5.0000',
            'is_higher_better' => false,
            'is_active' => true,
        ]);

        /** Zero target edge case indicator (weight 20%, target 0 -> 0.00%). */
        KpiIndicator::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'IND-ZERO',
            'name' => 'Zero Target Metric',
            'metric_type' => KpiMetricType::ManualNumeric,
            'unit' => 'poin',
            'default_weight' => '20.00',
            'default_target' => '0.0000',
            'is_higher_better' => true,
            'is_active' => true,
        ]);

        Attendance::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'employee_id' => $employee->getKey(),
            'attendance_date' => '2026-11-10',
            'status' => AttendanceStatus::Present,
            'late_minutes' => 0,
            'overtime_minutes' => 0,
            'source' => AttendanceSource::Manual,
        ]);

        $period = KpiPeriod::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'name' => 'Nov 2026',
            'period_type' => KpiPeriodType::Monthly,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => KpiPeriodStatus::Active,
        ]);

        $service = app(KpiEvaluationService::class);
        $evaluations = $service->generatePeriodEvaluations($period);
        $this->assertCount(1, $evaluations);

        /** Idempotent re-generation produces no duplicate evaluations or items. */
        $service->generatePeriodEvaluations($period);
        $this->assertSame(1, KpiEvaluation::query()->withoutGlobalScope('company')->count());
        $this->assertSame(3, KpiEvaluationItem::query()->withoutGlobalScope('company')->count());

        $evaluation = $evaluations->firstOrFail();

        /** Set lower-is-better manual actual to 2.0000 (target 5 / actual 2 * 100 = 250% -> capped at 120.00%). */
        $errorItem = $evaluation->items->firstWhere('kpi_indicator_id', $lowerIsBetterInd->getKey());
        $errorItem->update(['actual_value' => '2.0000']);

        $recalculated = $service->syncAndRecalculate($evaluation);

        /**
         * Expected weighted scores:
         * - IND-ATT: 120.00% * 0.40 = 48.00
         * - IND-ERR: 120.00% * 0.40 = 48.00
         * - IND-ZERO: 0.00% * 0.20 = 0.00
         * Total = 96.00 (Grade A, 10% of 10,000,000 = 1,000,000.0000).
         */
        $this->assertSame('100.00', $recalculated->total_weight);
        $this->assertSame('96.00', $recalculated->final_score);
        $this->assertSame('A', $recalculated->grade);
        $this->assertSame('1000000.0000', $recalculated->bonus_amount);
    }

    /**
     * Ensure department/position indicator filtering, weight != 100 approval rejection, and locked immutability work properly.
     */
    public function test_enforces_weight_validation_and_locked_evaluation_immutability(): void
    {
        /** Arrange department-scoped employee and indicators summing only to 60.00%. */
        $company = Company::factory()->create();
        $approver = User::factory()->create();

        $salesDept = Department::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'SLS',
            'name' => 'Sales',
            'is_active' => true,
        ]);
        $opsDept = Department::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'OPS',
            'name' => 'Operations',
            'is_active' => true,
        ]);
        $cashierPos = Position::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'department_id' => $salesDept->getKey(),
            'code' => 'CSH',
            'name' => 'Cashier',
        ]);

        $employee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'department_id' => $salesDept->getKey(),
            'position_id' => $cashierPos->getKey(),
            'employee_code' => 'EMP-KPI-02',
            'full_name' => 'Dedi KPI',
            'base_salary' => '5000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        /** Matching sales indicator (60%) and non-matching OPS indicator (40%). */
        KpiIndicator::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'department_id' => $salesDept->getKey(),
            'position_id' => $cashierPos->getKey(),
            'code' => 'IND-SALES',
            'name' => 'Sales Quota',
            'metric_type' => KpiMetricType::ManualNumeric,
            'unit' => 'pcs',
            'default_weight' => '60.00',
            'default_target' => '100.0000',
            'is_active' => true,
        ]);
        KpiIndicator::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'department_id' => $opsDept->getKey(),
            'code' => 'IND-OPS-ONLY',
            'name' => 'OPS Only',
            'metric_type' => KpiMetricType::ManualNumeric,
            'unit' => 'pcs',
            'default_weight' => '40.00',
            'default_target' => '100.0000',
            'is_active' => true,
        ]);

        $period = KpiPeriod::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'name' => 'Nov 2026',
            'period_type' => KpiPeriodType::Monthly,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => KpiPeriodStatus::Active,
        ]);

        $service = app(KpiEvaluationService::class);
        $evaluation = $service->generatePeriodEvaluations($period)->firstOrFail();

        $this->assertSame('60.00', $evaluation->total_weight);
        $this->assertCount(1, $evaluation->items);

        /** Submit succeeds, but approval fails because total_weight != 100.00. */
        $submitted = $service->submitEvaluation($evaluation);
        $this->assertSame(KpiEvaluationStatus::Submitted, $submitted->status);

        try {
            $service->approveEvaluation($submitted, $approver);
            $this->fail('Expected DomainException when approving evaluation with total_weight != 100.00.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        /** Adjust item weight to 100.00, recalculate, approve, and lock. */
        $submitted->items->first()->update(['weight' => '100.00', 'actual_value' => '100.0000']);
        $recalculated = $service->syncAndRecalculate($submitted);
        $approved = $service->approveEvaluation($recalculated, $approver);
        $this->assertSame(KpiEvaluationStatus::Approved, $approved->status);

        $locked = $service->lockEvaluation($approved);
        $this->assertSame(KpiEvaluationStatus::Locked, $locked->status);

        /** Locked evaluation rejects direct edits, recalculation, item modification, and deletion. */
        try {
            $locked->update(['notes' => 'Tampered']);
            $this->fail('Expected DomainException when updating a locked KPI evaluation.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        try {
            $service->syncAndRecalculate($locked);
            $this->fail('Expected DomainException when recalculating a locked KPI evaluation.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        try {
            $locked->items->first()->update(['actual_value' => '10.0000']);
            $this->fail('Expected DomainException when updating an item on a locked KPI evaluation.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->expectException(DomainException::class);
        $locked->delete();
    }
}
