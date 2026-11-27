<?php

declare(strict_types=1);

namespace Modules\Hris\Services;

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Branch;
use Modules\Hris\Enums\AttendanceStatus;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Enums\PayrollRunStatus;
use Modules\Hris\Enums\PayslipItemType;
use Modules\Hris\Events\PayrollPaid;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\PayrollRun;
use Modules\Hris\Models\Payslip;
use Modules\Hris\Models\PayslipItem;
use Modules\Kpi\Enums\KpiEvaluationStatus;
use Modules\Kpi\Models\KpiEvaluation;
use Modules\Platform\Models\CompanySetting;

/**
 * Generates monthly payroll runs, handles approval transitions, and settles payroll with PayrollPaid dispatch.
 */
class PayrollCalculationService
{
    private const MONEY_SCALE = 4;

    private const HOURLY_DIVISOR = '173';

    /**
     * Inject the tax and BPJS calculator service.
     */
    public function __construct(
        private readonly TaxAndBpjsCalculatorService $taxAndBpjsCalculator,
    ) {}

    /**
     * Generate a draft monthly payroll run with itemized payslips and aggregate totals.
     *
     * @param  array<int, array{overtime_pay?: string, bonus_total?: string, other_deductions?: string}>  $employeeAdjustments
     */
    public function generatePayrollRun(
        Company $company,
        int $periodMonth,
        int $periodYear,
        ?Branch $branch = null,
        ?bool $applyPph21 = null,
        ?bool $applyBpjs = null,
        array $employeeAdjustments = [],
        ?string $payrollNumber = null,
        ?string $notes = null,
    ): PayrollRun {
        if ($periodMonth < 1 || $periodMonth > 12) {
            throw new InvalidArgumentException('Period month must be between 1 and 12.');
        }

        if ($periodYear < 2000 || $periodYear > 2100) {
            throw new InvalidArgumentException('Period year is out of supported range.');
        }

        if ($branch instanceof Branch && (int) $branch->company_id !== (int) $company->getKey()) {
            throw new InvalidArgumentException('Branch does not belong to the specified company.');
        }

        $settings = CompanySetting::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->first();

        $effectiveApplyPph21 = $applyPph21 ?? (bool) ($settings?->enable_pph21 ?? false);
        $effectiveApplyBpjs = $applyBpjs ?? (bool) (($settings?->enable_bpjs_kesehatan ?? false) || ($settings?->enable_bpjs_ketenagakerjaan ?? false));

        $employees = Employee::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('employment_status', EmploymentStatus::Active->value)
            ->when($branch instanceof Branch, static function ($query) use ($branch): void {
                $query->where('branch_id', $branch->getKey());
            })
            ->orderBy('employee_code')
            ->get();

        if ($employees->isEmpty()) {
            throw new DomainException('No active employees found for payroll generation.');
        }

        $periodStart = Carbon::createFromDate($periodYear, $periodMonth, 1)->startOfMonth()->toDateString();
        $periodEnd = Carbon::createFromDate($periodYear, $periodMonth, 1)->endOfMonth()->toDateString();

        return DB::transaction(function () use (
            $company,
            $branch,
            $settings,
            $employees,
            $periodMonth,
            $periodYear,
            $periodStart,
            $periodEnd,
            $effectiveApplyPph21,
            $effectiveApplyBpjs,
            $employeeAdjustments,
            $payrollNumber,
            $notes,
        ): PayrollRun {
            $payrollRun = PayrollRun::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $company->getKey(),
                    'branch_id' => $branch?->getKey(),
                    'payroll_number' => $payrollNumber ?? $this->generatePayrollNumber($periodYear, $periodMonth),
                    'period_month' => $periodMonth,
                    'period_year' => $periodYear,
                    'status' => PayrollRunStatus::Draft,
                    'apply_pph21' => $effectiveApplyPph21,
                    'apply_bpjs' => $effectiveApplyBpjs,
                    'notes' => $notes,
                ]);

            $attendancesByEmployee = Attendance::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $company->getKey())
                ->whereIn('employee_id', $employees->pluck('id'))
                ->whereDate('attendance_date', '>=', $periodStart)
                ->whereDate('attendance_date', '<=', $periodEnd)
                ->get()
                ->groupBy('employee_id');

            $totalGross = '0.0000';
            $totalOtherDeductions = '0.0000';
            $totalPph21 = '0.0000';
            $totalBpjsEmployee = '0.0000';
            $totalBpjsCompany = '0.0000';
            $totalNet = '0.0000';

            foreach ($employees as $employee) {
                $attendanceSummary = $this->summarizeAttendances($attendancesByEmployee->get($employee->getKey(), collect()));
                $adjustments = $employeeAdjustments[$employee->getKey()] ?? [];

                $baseSalary = bcadd((string) $employee->base_salary, '0', self::MONEY_SCALE);
                $allowancesTotal = bcadd((string) $employee->fixed_allowance, '0', self::MONEY_SCALE);
                $overtimePay = isset($adjustments['overtime_pay'])
                    ? $this->normalizeNonNegativeMoney((string) $adjustments['overtime_pay'], 'overtime_pay')
                    : $this->calculateOvertimePay($baseSalary, $allowancesTotal, $attendanceSummary['overtime_minutes']);
                $manualBonusTotal = isset($adjustments['bonus_total'])
                    ? $this->normalizeNonNegativeMoney((string) $adjustments['bonus_total'], 'bonus_total')
                    : '0.0000';
                $eligibleKpiEvaluations = $this->resolveEligibleKpiEvaluations(
                    company: $company,
                    employee: $employee,
                    periodMonth: $periodMonth,
                    periodYear: $periodYear,
                );
                $kpiBonusTotal = $this->sumKpiBonusTotal($eligibleKpiEvaluations);
                $bonusTotal = bcadd($manualBonusTotal, $kpiBonusTotal, self::MONEY_SCALE);
                $otherDeductions = isset($adjustments['other_deductions'])
                    ? $this->normalizeNonNegativeMoney((string) $adjustments['other_deductions'], 'other_deductions')
                    : '0.0000';

                $grossSalary = bcadd(
                    bcadd($baseSalary, $allowancesTotal, self::MONEY_SCALE),
                    bcadd($overtimePay, $bonusTotal, self::MONEY_SCALE),
                    self::MONEY_SCALE,
                );

                $taxResult = $this->taxAndBpjsCalculator->calculate(
                    employee: $employee,
                    settings: $settings,
                    runApplyPph21: $effectiveApplyPph21,
                    runApplyBpjs: $effectiveApplyBpjs,
                    baseSalary: $baseSalary,
                    allowancesTotal: $allowancesTotal,
                    overtimePay: $overtimePay,
                    bonusTotal: $bonusTotal,
                    periodYear: $periodYear,
                );

                $employeeBpjs = bcadd($taxResult->bpjsKesehatanEmployee, $taxResult->bpjsTkEmployee, self::MONEY_SCALE);
                $companyBpjs = bcadd($taxResult->bpjsKesehatanCompany, $taxResult->bpjsTkCompany, self::MONEY_SCALE);
                $employeeTotalDeductions = bcadd(
                    bcadd($taxResult->pph21Amount, $employeeBpjs, self::MONEY_SCALE),
                    $otherDeductions,
                    self::MONEY_SCALE,
                );
                $netSalary = bcsub($grossSalary, $employeeTotalDeductions, self::MONEY_SCALE);

                if (bccomp($netSalary, '0.0000', self::MONEY_SCALE) < 0) {
                    throw new DomainException("Net salary cannot be negative for employee '{$employee->employee_code}'.");
                }

                $payslip = Payslip::query()
                    ->withoutGlobalScope('company')
                    ->create([
                        'company_id' => $company->getKey(),
                        'payroll_run_id' => $payrollRun->getKey(),
                        'employee_id' => $employee->getKey(),
                        'base_salary' => $baseSalary,
                        'allowances_total' => $allowancesTotal,
                        'overtime_pay' => $overtimePay,
                        'bonus_total' => $bonusTotal,
                        'gross_salary' => $grossSalary,
                        'pph21_amount' => $taxResult->pph21Amount,
                        'bpjs_kesehatan_employee' => $taxResult->bpjsKesehatanEmployee,
                        'bpjs_kesehatan_company' => $taxResult->bpjsKesehatanCompany,
                        'bpjs_tk_employee' => $taxResult->bpjsTkEmployee,
                        'bpjs_tk_company' => $taxResult->bpjsTkCompany,
                        'other_deductions' => $otherDeductions,
                        'net_salary' => $netSalary,
                        'attendance_days' => $attendanceSummary['attendance_days'],
                        'absent_days' => $attendanceSummary['absent_days'],
                        'late_minutes' => $attendanceSummary['late_minutes'],
                        'overtime_minutes' => $attendanceSummary['overtime_minutes'],
                    ]);

                $this->createPayslipBreakdownItems($payslip, [
                    [PayslipItemType::Earning, 'BASE_SALARY', 'Gaji Pokok', $baseSalary],
                    [PayslipItemType::Earning, 'FIXED_ALLOWANCE', 'Tunjangan Tetap', $allowancesTotal],
                    [PayslipItemType::Earning, 'OVERTIME_PAY', 'Upah Lembur', $overtimePay],
                    [PayslipItemType::Earning, 'BONUS', 'Bonus', $manualBonusTotal],
                    [PayslipItemType::Earning, 'KPI_BONUS', 'Bonus Performa KPI', $kpiBonusTotal],
                    [PayslipItemType::Deduction, 'PPH21', 'PPh 21 (TER)', $taxResult->pph21Amount],
                    [PayslipItemType::Deduction, 'BPJS_KES_EMP', 'BPJS Kesehatan (Karyawan)', $taxResult->bpjsKesehatanEmployee],
                    [PayslipItemType::Deduction, 'BPJS_TK_EMP', 'BPJS Ketenagakerjaan (Karyawan)', $taxResult->bpjsTkEmployee],
                    [PayslipItemType::Deduction, 'OTHER_DEDUCTION', 'Potongan Lainnya', $otherDeductions],
                    [PayslipItemType::CompanyContribution, 'BPJS_KES_COMP', 'BPJS Kesehatan (Perusahaan)', $taxResult->bpjsKesehatanCompany],
                    [PayslipItemType::CompanyContribution, 'BPJS_TK_COMP', 'BPJS Ketenagakerjaan (Perusahaan)', $taxResult->bpjsTkCompany],
                ]);

                $this->markKpiEvaluationsApplied($eligibleKpiEvaluations, $payslip);

                $totalGross = bcadd($totalGross, $grossSalary, self::MONEY_SCALE);
                $totalOtherDeductions = bcadd($totalOtherDeductions, $otherDeductions, self::MONEY_SCALE);
                $totalPph21 = bcadd($totalPph21, $taxResult->pph21Amount, self::MONEY_SCALE);
                $totalBpjsEmployee = bcadd($totalBpjsEmployee, $employeeBpjs, self::MONEY_SCALE);
                $totalBpjsCompany = bcadd($totalBpjsCompany, $companyBpjs, self::MONEY_SCALE);
                $totalNet = bcadd($totalNet, $netSalary, self::MONEY_SCALE);
            }

            $payrollRun->update([
                'total_gross' => $totalGross,
                'total_other_deductions' => $totalOtherDeductions,
                'total_pph21' => $totalPph21,
                'total_bpjs_employee' => $totalBpjsEmployee,
                'total_bpjs_company' => $totalBpjsCompany,
                'total_net' => $totalNet,
            ]);

            return $payrollRun->refresh()->load('payslips.items');
        });
    }

    /**
     * Transition a draft payroll run to approved status prior to payment settlement.
     */
    public function approvePayrollRun(PayrollRun $payrollRun, User $approver): PayrollRun
    {
        return DB::transaction(function () use ($payrollRun, $approver): PayrollRun {
            $lockedRun = PayrollRun::query()
                ->withoutGlobalScope('company')
                ->whereKey($payrollRun->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedRun instanceof PayrollRun) {
                throw new DomainException('Payroll run not found.');
            }

            if ($lockedRun->status !== PayrollRunStatus::Draft) {
                throw new DomainException('Only draft payroll runs can be approved.');
            }

            $lockedRun->update([
                'status' => PayrollRunStatus::Approved,
                'approved_by' => $approver->getKey(),
                'approved_at' => now(),
            ]);

            return $lockedRun->refresh();
        });
    }

    /**
     * Settle an approved payroll run and dispatch PayrollPaid exactly once.
     */
    public function payPayrollRun(
        PayrollRun $payrollRun,
        ?Account $paymentAccount = null,
        ?CarbonInterface $paidAt = null,
    ): PayrollRun {
        /** @var PayrollRun $paidRun */
        $paidRun = DB::transaction(function () use ($payrollRun, $paymentAccount, $paidAt): PayrollRun {
            $lockedRun = PayrollRun::query()
                ->withoutGlobalScope('company')
                ->whereKey($payrollRun->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedRun instanceof PayrollRun) {
                throw new DomainException('Payroll run not found.');
            }

            if ($lockedRun->status !== PayrollRunStatus::Approved) {
                throw new DomainException('Only approved payroll runs can be marked as paid.');
            }

            if ($paymentAccount instanceof Account && (int) $paymentAccount->company_id !== (int) $lockedRun->company_id) {
                throw new InvalidArgumentException('Payment account does not belong to the payroll company.');
            }

            $lockedRun->update([
                'status' => PayrollRunStatus::Paid,
                'payment_account_id' => $paymentAccount?->getKey() ?? $lockedRun->payment_account_id,
                'paid_at' => $paidAt ?? now(),
            ]);

            return $lockedRun->refresh();
        });

        PayrollPaid::dispatch($paidRun);

        return $paidRun;
    }

    /**
     * Summarize monthly attendance days, absences, lateness, and overtime minutes for an employee.
     *
     * @param  \Illuminate\Support\Collection<int, Attendance>  $records
     * @return array{attendance_days: int, absent_days: int, late_minutes: int, overtime_minutes: int}
     */
    private function summarizeAttendances(\Illuminate\Support\Collection $records): array
    {
        $attendanceDays = 0;
        $absentDays = 0;
        $lateMinutes = 0;
        $overtimeMinutes = 0;

        foreach ($records as $record) {
            if (in_array($record->status, [AttendanceStatus::Present, AttendanceStatus::Late], true)) {
                $attendanceDays++;
            }

            if ($record->status === AttendanceStatus::Absent) {
                $absentDays++;
            }

            $lateMinutes += (int) $record->late_minutes;
            $overtimeMinutes += (int) $record->overtime_minutes;
        }

        return [
            'attendance_days' => $attendanceDays,
            'absent_days' => $absentDays,
            'late_minutes' => $lateMinutes,
            'overtime_minutes' => $overtimeMinutes,
        ];
    }

    /**
     * Compute overtime pay using Indonesian PP 35/2021 standard hourly base (1/173 of fixed wage).
     */
    private function calculateOvertimePay(string $baseSalary, string $allowancesTotal, int $overtimeMinutes): string
    {
        if ($overtimeMinutes <= 0) {
            return '0.0000';
        }

        $fixedWage = bcadd($baseSalary, $allowancesTotal, self::MONEY_SCALE);
        $hourlyWage = bcdiv($fixedWage, self::HOURLY_DIVISOR, 8);
        $overtimeHours = bcdiv((string) $overtimeMinutes, '60', 8);

        return bcmul($hourlyWage, $overtimeHours, self::MONEY_SCALE);
    }

    /**
     * Persist non-zero itemized earning, deduction, and employer contribution rows for a payslip.
     *
     * @param  list<array{0: PayslipItemType, 1: string, 2: string, 3: string}>  $definitions
     */
    private function createPayslipBreakdownItems(Payslip $payslip, array $definitions): void
    {
        foreach ($definitions as [$type, $code, $name, $amount]) {
            if (bccomp($amount, '0.0000', self::MONEY_SCALE) <= 0) {
                continue;
            }

            PayslipItem::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $payslip->company_id,
                    'payslip_id' => $payslip->getKey(),
                    'type' => $type,
                    'code' => $code,
                    'name' => $name,
                    'amount' => $amount,
                ]);
        }
    }

    /**
     * Validate numeric decimal syntax and reject negative adjustment values.
     */
    private function normalizeNonNegativeMoney(string $value, string $field): string
    {
        $trimmed = trim($value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException("Invalid decimal format for {$field}.");
        }

        $normalized = bcadd($trimmed, '0', self::MONEY_SCALE);

        if (bccomp($normalized, '0.0000', self::MONEY_SCALE) < 0) {
            throw new InvalidArgumentException("Adjustment {$field} cannot be negative.");
        }

        return $normalized;
    }

    /**
     * Resolve approved or locked KPI evaluations eligible for payout in the given payroll period.
     *
     * @return \Illuminate\Support\Collection<int, KpiEvaluation>
     */
    private function resolveEligibleKpiEvaluations(
        Company $company,
        Employee $employee,
        int $periodMonth,
        int $periodYear,
    ): \Illuminate\Support\Collection {
        if (! $company->hasActiveModule('kpi')) {
            return collect();
        }

        return KpiEvaluation::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', [
                KpiEvaluationStatus::Approved->value,
                KpiEvaluationStatus::Locked->value,
            ])
            ->whereNull('bonus_applied_to_payslip_id')
            ->whereHas('period', static function ($query) use ($company, $periodMonth, $periodYear): void {
                $query->withoutGlobalScope('company')
                    ->where('company_id', $company->getKey())
                    ->where('payroll_period_month', $periodMonth)
                    ->where('payroll_period_year', $periodYear);
            })
            ->lockForUpdate()
            ->get()
            ->filter(static fn (KpiEvaluation $evaluation): bool => bccomp(
                (string) $evaluation->bonus_amount,
                '0.0000',
                self::MONEY_SCALE,
            ) > 0)
            ->values();
    }

    /**
     * Sum monetary bonus amounts across eligible KPI evaluations using bcmath precision.
     *
     * @param  \Illuminate\Support\Collection<int, KpiEvaluation>  $evaluations
     */
    private function sumKpiBonusTotal(\Illuminate\Support\Collection $evaluations): string
    {
        $total = '0.0000';

        foreach ($evaluations as $evaluation) {
            $total = bcadd($total, (string) $evaluation->bonus_amount, self::MONEY_SCALE);
        }

        return $total;
    }

    /**
     * Link paid-out KPI evaluations to the generated payslip and lock them against duplicate payouts.
     *
     * @param  \Illuminate\Support\Collection<int, KpiEvaluation>  $evaluations
     */
    private function markKpiEvaluationsApplied(\Illuminate\Support\Collection $evaluations, Payslip $payslip): void
    {
        foreach ($evaluations as $evaluation) {
            $updates = [
                'bonus_applied_to_payslip_id' => $payslip->getKey(),
            ];

            if ($evaluation->status !== KpiEvaluationStatus::Locked) {
                $updates['status'] = KpiEvaluationStatus::Locked;
            }

            $evaluation->update($updates);
        }
    }

    /**
     * Generate a unique monthly payroll batch number prefixed with PAY-.
     */
    private function generatePayrollNumber(int $periodYear, int $periodMonth): string
    {
        return sprintf('PAY-%04d%02d-%s', $periodYear, $periodMonth, Str::upper(Str::random(6)));
    }
}
