<?php

declare(strict_types=1);

namespace Modules\Kpi\Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Hris\Models\Employee;
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
use Modules\Platform\Actions\ActivateCompanyModulesAction;
use Modules\Platform\Enums\CompanyModuleStatus;
use Modules\Platform\Models\SaasModule;
use Modules\Transaction\Enums\PaymentStatus;
use Modules\Transaction\Models\Order;

/**
 * Idempotently activates KPI for Toko Maju and seeds bonus tiers, indicators, active period, and sample evaluation.
 */
class KpiDemoSeeder extends Seeder
{
    /**
     * Run the database seeds in local/testing environments.
     */
    public function run(): void
    {
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->first();

        if (! $tokoMaju instanceof Company) {
            return;
        }

        /** Activate KPI strictly for Toko Maju so Gym Sehat remains unsubscribed for RequiresModule checks. */
        $kpiModule = SaasModule::query()->where('slug', 'kpi')->first();

        if ($kpiModule instanceof SaasModule) {
            app(ActivateCompanyModulesAction::class)->execute(
                company: $tokoMaju,
                moduleIds: [$kpiModule->getKey()],
                expiresAt: now()->addYear(),
                status: CompanyModuleStatus::Active,
            );
        }

        $companyId = $tokoMaju->getKey();

        $this->seedBonusTiers($companyId);
        $this->seedIndicators($companyId);

        $empOne = Employee::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('employee_code', 'EMP-001')
            ->first();

        if ($empOne instanceof Employee) {
            Order::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('payment_status', PaymentStatus::Paid->value)
                ->whereNull('salesperson_employee_id')
                ->update(['salesperson_employee_id' => $empOne->getKey()]);
        }

        $now = now();
        $periodMonth = (int) $now->month;
        $periodYear = (int) $now->year;

        $period = KpiPeriod::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'period_type' => KpiPeriodType::Monthly->value,
                    'payroll_period_year' => $periodYear,
                    'payroll_period_month' => $periodMonth,
                ],
                [
                    'name' => sprintf('Evaluasi KPI %s', $now->format('F Y')),
                    'start_date' => $now->copy()->startOfMonth()->toDateString(),
                    'end_date' => $now->copy()->endOfMonth()->toDateString(),
                    'status' => KpiPeriodStatus::Active,
                    'notes' => 'Periode KPI aktif bulan berjalan yang terhubung ke Payroll.',
                ],
            );

        $evaluationService = app(KpiEvaluationService::class);
        $evaluationService->generatePeriodEvaluations($period);

        if (! $empOne instanceof Employee) {
            return;
        }

        $empOneEvaluation = KpiEvaluation::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('kpi_period_id', $period->getKey())
            ->where('employee_id', $empOne->getKey())
            ->first();

        if (! $empOneEvaluation instanceof KpiEvaluation || $empOneEvaluation->status !== KpiEvaluationStatus::Draft) {
            return;
        }

        $manualItem = KpiEvaluationItem::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('kpi_evaluation_id', $empOneEvaluation->getKey())
            ->where('metric_type_snapshot', KpiMetricType::ManualPercentage->value)
            ->first();

        if ($manualItem instanceof KpiEvaluationItem) {
            $manualItem->update([
                'actual_value' => '90.0000',
                'notes' => 'Penilaian kualitas layanan pelanggan oleh supervisor.',
            ]);
        }

        $recalculated = $evaluationService->syncAndRecalculate($empOneEvaluation);

        $approver = User::query()->where('email', 'owner@test.com')->first();

        if ($approver instanceof User) {
            $evaluationService->approveEvaluation($recalculated, $approver);
        }
    }

    /**
     * Seed the four standard KPI grade bonus tiers for the demo tenant.
     */
    private function seedBonusTiers(int $companyId): void
    {
        $tiers = [
            [
                'grade' => 'A',
                'min_score' => '95.00',
                'max_score' => '999.99',
                'bonus_type' => KpiBonusType::PercentageOfBaseSalary,
                'bonus_value' => '15.0000',
            ],
            [
                'grade' => 'B',
                'min_score' => '85.00',
                'max_score' => '94.99',
                'bonus_type' => KpiBonusType::PercentageOfBaseSalary,
                'bonus_value' => '10.0000',
            ],
            [
                'grade' => 'C',
                'min_score' => '75.00',
                'max_score' => '84.99',
                'bonus_type' => KpiBonusType::PercentageOfBaseSalary,
                'bonus_value' => '5.0000',
            ],
            [
                'grade' => 'D',
                'min_score' => '0.00',
                'max_score' => '74.99',
                'bonus_type' => KpiBonusType::FixedAmount,
                'bonus_value' => '0.0000',
            ],
        ];

        foreach ($tiers as $tier) {
            KpiBonusTier::query()
                ->withoutGlobalScope('company')
                ->updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'grade' => $tier['grade'],
                    ],
                    [
                        'min_score' => $tier['min_score'],
                        'max_score' => $tier['max_score'],
                        'bonus_type' => $tier['bonus_type'],
                        'bonus_value' => $tier['bonus_value'],
                        'is_active' => true,
                    ],
                );
        }
    }

    /**
     * Seed the four balanced KPI indicators totaling 100.00% weight for Toko Maju.
     */
    private function seedIndicators(int $companyId): void
    {
        $indicators = [
            [
                'code' => 'KPI-SLS-01',
                'name' => 'Target Omzet Penjualan',
                'description' => 'Total omzet pesanan lunas yang ditangani karyawan dalam periode evaluasi.',
                'metric_type' => KpiMetricType::SalesRevenue,
                'unit' => 'IDR',
                'default_weight' => '40.00',
                'default_target' => '150000.0000',
            ],
            [
                'code' => 'KPI-ATT-01',
                'name' => 'Tingkat Kehadiran',
                'description' => 'Persentase kehadiran kerja dari total hari absensi tercatat.',
                'metric_type' => KpiMetricType::AttendanceRate,
                'unit' => '%',
                'default_weight' => '25.00',
                'default_target' => '95.0000',
            ],
            [
                'code' => 'KPI-PNC-01',
                'name' => 'Ketepatan Waktu',
                'description' => 'Persentase kehadiran tepat waktu tanpa keterlambatan.',
                'metric_type' => KpiMetricType::PunctualityRate,
                'unit' => '%',
                'default_weight' => '20.00',
                'default_target' => '90.0000',
            ],
            [
                'code' => 'KPI-SVC-01',
                'name' => 'Kualitas Layanan Pelanggan',
                'description' => 'Penilaian kualitatif pelayanan pelanggan oleh atasan langsung.',
                'metric_type' => KpiMetricType::ManualPercentage,
                'unit' => '%',
                'default_weight' => '15.00',
                'default_target' => '90.0000',
            ],
        ];

        foreach ($indicators as $indicator) {
            KpiIndicator::query()
                ->withoutGlobalScope('company')
                ->updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'code' => $indicator['code'],
                    ],
                    [
                        'department_id' => null,
                        'position_id' => null,
                        'name' => $indicator['name'],
                        'description' => $indicator['description'],
                        'metric_type' => $indicator['metric_type'],
                        'unit' => $indicator['unit'],
                        'default_weight' => $indicator['default_weight'],
                        'default_target' => $indicator['default_target'],
                        'is_higher_better' => true,
                        'is_active' => true,
                    ],
                );
        }
    }
}
