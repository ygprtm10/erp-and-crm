<?php

declare(strict_types=1);

namespace Modules\Kpi\Tests\Unit;

use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Branch;
use Modules\Hris\Database\Seeders\TaxMasterSeeder;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Enums\PayslipItemType;
use Modules\Hris\Enums\PtkpStatus;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\PayslipItem;
use Modules\Hris\Services\PayrollCalculationService;
use Modules\Kpi\Enums\KpiBonusType;
use Modules\Kpi\Enums\KpiEvaluationStatus;
use Modules\Kpi\Enums\KpiMetricType;
use Modules\Kpi\Enums\KpiPeriodStatus;
use Modules\Kpi\Enums\KpiPeriodType;
use Modules\Kpi\Models\KpiBonusTier;
use Modules\Kpi\Models\KpiEvaluation;
use Modules\Kpi\Models\KpiIndicator;
use Modules\Kpi\Models\KpiPeriod;
use Modules\Kpi\Services\KpiEvaluationService;
use Modules\Platform\Actions\ActivateCompanyModulesAction;
use Modules\Platform\Database\Seeders\SaasModuleSeeder;
use Modules\Platform\Enums\CompanyModuleStatus;
use Modules\Platform\Models\SaasModule;
use Tests\TestCase;

/**
 * End-to-end integration tests verifying approved KPI bonuses flow into HRIS payslips idempotently.
 */
class KpiPayrollIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure approved KPI bonuses are included in payslip bonus_total, KPI_BONUS item, and locked idempotently.
     */
    public function test_approved_kpi_bonus_integrates_into_payroll_payslip_and_locks_idempotently(): void
    {
        /** Arrange active KPI module, employee, bonus tier, indicator, and approved November 2026 evaluation. */
        $this->seed([
            SaasModuleSeeder::class,
            TaxMasterSeeder::class,
        ]);

        $company = Company::factory()->create();
        $approver = User::factory()->create();
        $branch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'MAIN',
            'name' => 'Main Branch',
            'is_main' => true,
        ]);

        $kpiModule = SaasModule::query()->where('slug', 'kpi')->firstOrFail();
        app(ActivateCompanyModulesAction::class)->execute(
            company: $company,
            moduleIds: [$kpiModule->getKey()],
            expiresAt: now()->addYear(),
            status: CompanyModuleStatus::Active,
        );

        $employee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'branch_id' => $branch->getKey(),
            'employee_code' => 'EMP-PAY-01',
            'full_name' => 'Adi Performa',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '8000000.0000',
            'fixed_allowance' => '0.0000',
            'enable_pph21' => false,
            'enable_bpjs_kesehatan' => false,
            'enable_bpjs_ketenagakerjaan' => false,
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        KpiBonusTier::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'min_score' => '90.00',
            'max_score' => '120.00',
            'grade' => 'A',
            'bonus_type' => KpiBonusType::PercentageOfBaseSalary,
            'bonus_value' => '15.0000',
            'is_active' => true,
        ]);

        KpiIndicator::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'KPI-MANUAL',
            'name' => 'Target Kinerja',
            'metric_type' => KpiMetricType::ManualPercentage,
            'unit' => '%',
            'default_weight' => '100.00',
            'default_target' => '100.0000',
            'is_active' => true,
        ]);

        $period = KpiPeriod::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'name' => 'KPI Nov 2026',
            'period_type' => KpiPeriodType::Monthly,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => KpiPeriodStatus::Active,
            'payroll_period_month' => 11,
            'payroll_period_year' => 2026,
        ]);

        $kpiService = app(KpiEvaluationService::class);
        $evaluation = $kpiService->generatePeriodEvaluations($period)->firstOrFail();
        $evaluation->items->first()->update(['actual_value' => '95.0000']);
        $recalculated = $kpiService->syncAndRecalculate($evaluation);
        $approvedEvaluation = $kpiService->approveEvaluation($recalculated, $approver);

        /** Expected bonus: 15% of 8,000,000 = 1,200,000.0000. */
        $this->assertSame('1200000.0000', $approvedEvaluation->bonus_amount);

        /** Act by generating the November 2026 payroll run. */
        $payrollService = app(PayrollCalculationService::class);
        $firstRun = $payrollService->generatePayrollRun(
            company: $company,
            periodMonth: 11,
            periodYear: 2026,
            branch: $branch,
            applyPph21: false,
            applyBpjs: false,
        );

        $firstPayslip = $firstRun->payslips->firstOrFail();

        /** Assert payslip bonus_total, gross_salary, net_salary, and KPI_BONUS item. */
        $this->assertSame('1200000.0000', $firstPayslip->bonus_total);
        $this->assertSame('9200000.0000', $firstPayslip->gross_salary);
        $this->assertSame('9200000.0000', $firstPayslip->net_salary);

        $kpiBonusItem = PayslipItem::query()
            ->withoutGlobalScope('company')
            ->where('payslip_id', $firstPayslip->getKey())
            ->where('code', 'KPI_BONUS')
            ->firstOrFail();

        $this->assertSame(PayslipItemType::Earning, $kpiBonusItem->type);
        $this->assertSame('Bonus Performa KPI', $kpiBonusItem->name);
        $this->assertSame('1200000.0000', $kpiBonusItem->amount);

        /** Assert evaluation is now linked to payslip and locked against further edits. */
        $approvedEvaluation->refresh();
        $this->assertSame($firstPayslip->getKey(), $approvedEvaluation->bonus_applied_to_payslip_id);
        $this->assertSame(KpiEvaluationStatus::Locked, $approvedEvaluation->status);

        /** Running payroll again for the same period must NOT pay the KPI bonus twice. */
        $secondRun = $payrollService->generatePayrollRun(
            company: $company,
            periodMonth: 11,
            periodYear: 2026,
            branch: $branch,
            applyPph21: false,
            applyBpjs: false,
        );
        $secondPayslip = $secondRun->payslips->firstOrFail();
        $this->assertSame('0.0000', $secondPayslip->bonus_total);
        $this->assertSame('8000000.0000', $secondPayslip->gross_salary);
        $this->assertFalse(
            PayslipItem::query()
                ->withoutGlobalScope('company')
                ->where('payslip_id', $secondPayslip->getKey())
                ->where('code', 'KPI_BONUS')
                ->exists(),
        );

        /** Applied evaluation remains immutable against tampering. */
        $this->expectException(DomainException::class);
        $approvedEvaluation->update(['notes' => 'Tampered after payroll']);
    }

    /**
     * Ensure KPI bonus payout is skipped when the tenant does not have an active KPI module subscription.
     */
    public function test_skips_kpi_bonus_payout_when_kpi_module_is_inactive(): void
    {
        $this->seed([
            SaasModuleSeeder::class,
            TaxMasterSeeder::class,
        ]);

        $company = Company::factory()->create();
        $approver = User::factory()->create();
        $branch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'MAIN',
            'name' => 'Main Branch',
            'is_main' => true,
        ]);

        $employee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'branch_id' => $branch->getKey(),
            'employee_code' => 'EMP-NO-KPI',
            'full_name' => 'No KPI Module',
            'base_salary' => '5000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        $period = KpiPeriod::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'name' => 'KPI Nov 2026',
            'period_type' => KpiPeriodType::Monthly,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => KpiPeriodStatus::Active,
            'payroll_period_month' => 11,
            'payroll_period_year' => 2026,
        ]);

        KpiEvaluation::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'kpi_period_id' => $period->getKey(),
            'employee_id' => $employee->getKey(),
            'total_weight' => '100.00',
            'final_score' => '95.00',
            'grade' => 'A',
            'bonus_amount' => '750000.0000',
            'status' => KpiEvaluationStatus::Approved,
            'approved_by' => $approver->getKey(),
            'approved_at' => now(),
        ]);

        $run = app(PayrollCalculationService::class)->generatePayrollRun(
            company: $company,
            periodMonth: 11,
            periodYear: 2026,
            branch: $branch,
            applyPph21: false,
            applyBpjs: false,
        );

        $payslip = $run->payslips->firstOrFail();
        $this->assertSame('0.0000', $payslip->bonus_total);
        $this->assertSame('5000000.0000', $payslip->net_salary);
    }
}
