<?php

declare(strict_types=1);

namespace Modules\Kpi\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Models\Employee;
use Modules\Kpi\Enums\KpiEvaluationStatus;
use Modules\Kpi\Models\KpiEvaluation;
use Modules\Kpi\Models\KpiEvaluationItem;
use Modules\Kpi\Models\KpiIndicator;
use Modules\Kpi\Models\KpiPeriod;

/**
 * Orchestrates KPI period evaluation generation, metric sync/recalculation, and approval lifecycle transitions.
 */
class KpiEvaluationService
{
    private const MONEY_SCALE = 4;

    private const SCORE_SCALE = 2;

    private const DIVISION_SCALE = 8;

    private const MAX_ACHIEVEMENT_PERCENTAGE = '120.00';

    private const REQUIRED_APPROVAL_WEIGHT = '100.00';

    /**
     * Inject metric synchronization and bonus calculation services.
     */
    public function __construct(
        private readonly KpiMetricSyncService $metricSyncService,
        private readonly KpiBonusCalculatorService $bonusCalculator,
    ) {}

    /**
     * Idempotently generate draft KPI evaluations and matching indicator items for all active employees in a period.
     *
     * @return Collection<int, KpiEvaluation>
     */
    public function generatePeriodEvaluations(KpiPeriod $period): Collection
    {
        return DB::transaction(function () use ($period): Collection {
            $employees = Employee::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $period->company_id)
                ->where('employment_status', EmploymentStatus::Active->value)
                ->orderBy('employee_code')
                ->get();

            $indicators = KpiIndicator::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $period->company_id)
                ->where('is_active', true)
                ->orderBy('code')
                ->get();

            $evaluations = new Collection();

            foreach ($employees as $employee) {
                $evaluation = KpiEvaluation::query()
                    ->withoutGlobalScope('company')
                    ->where('company_id', $period->company_id)
                    ->where('kpi_period_id', $period->getKey())
                    ->where('employee_id', $employee->getKey())
                    ->first();

                /** Preserve evaluations that have already progressed past draft status on repeated runs. */
                if ($evaluation instanceof KpiEvaluation && $evaluation->status !== KpiEvaluationStatus::Draft) {
                    $evaluations->push($evaluation->load('items'));

                    continue;
                }

                if (! $evaluation instanceof KpiEvaluation) {
                    $evaluation = KpiEvaluation::query()
                        ->withoutGlobalScope('company')
                        ->create([
                            'company_id' => $period->company_id,
                            'kpi_period_id' => $period->getKey(),
                            'employee_id' => $employee->getKey(),
                            'status' => KpiEvaluationStatus::Draft,
                        ]);
                }

                $matchingIndicators = $this->filterIndicatorsForEmployee($indicators, $employee);

                foreach ($matchingIndicators as $indicator) {
                    KpiEvaluationItem::query()
                        ->withoutGlobalScope('company')
                        ->firstOrCreate(
                            [
                                'company_id' => $period->company_id,
                                'kpi_evaluation_id' => $evaluation->getKey(),
                                'kpi_indicator_id' => $indicator->getKey(),
                            ],
                            [
                                'indicator_name_snapshot' => $indicator->name,
                                'metric_type_snapshot' => $indicator->metric_type,
                                'weight' => $indicator->default_weight,
                                'target_value' => $indicator->default_target,
                                'actual_value' => '0.0000',
                                'achievement_percentage' => '0.00',
                                'weighted_score' => '0.00',
                                'is_auto_synced' => false,
                            ],
                        );
                }

                $evaluations->push($this->syncAndRecalculate($evaluation));
            }

            return $evaluations;
        });
    }

    /**
     * Synchronize auto-pulled metrics and recalculate item achievements, weighted scores, and bonus totals.
     */
    public function syncAndRecalculate(KpiEvaluation $evaluation): KpiEvaluation
    {
        return DB::transaction(function () use ($evaluation): KpiEvaluation {
            $lockedEvaluation = KpiEvaluation::query()
                ->withoutGlobalScope('company')
                ->whereKey($evaluation->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedEvaluation instanceof KpiEvaluation) {
                throw new DomainException('KPI evaluation not found.');
            }

            if ($lockedEvaluation->isImmutable()) {
                throw new DomainException('Locked or payroll-applied KPI evaluations cannot be recalculated.');
            }

            $lockedEvaluation->load(['period', 'employee', 'items.indicator']);

            $period = $lockedEvaluation->period;
            $employee = $lockedEvaluation->employee;

            $totalWeight = '0.00';
            $finalScore = '0.00';

            foreach ($lockedEvaluation->items as $item) {
                $indicator = $item->indicator;
                $metricType = $indicator?->metric_type ?? $item->metric_type_snapshot;
                $isHigherBetter = $indicator?->is_higher_better ?? true;

                $syncedValue = $this->metricSyncService->resolveMetricValue($metricType, $employee, $period);

                if ($syncedValue !== null) {
                    $actualValue = bcadd($syncedValue, '0', self::MONEY_SCALE);
                    $isAutoSynced = true;
                } else {
                    $actualValue = bcadd((string) $item->actual_value, '0', self::MONEY_SCALE);
                    $isAutoSynced = false;
                }

                $achievementPercentage = $this->calculateAchievementPercentage(
                    targetValue: (string) $item->target_value,
                    actualValue: $actualValue,
                    isHigherBetter: $isHigherBetter,
                );

                $weight = bcadd((string) $item->weight, '0', self::SCORE_SCALE);
                $weightedScore = bcmul(
                    $achievementPercentage,
                    bcdiv($weight, '100', self::DIVISION_SCALE),
                    self::SCORE_SCALE,
                );

                $item->update([
                    'actual_value' => $actualValue,
                    'achievement_percentage' => $achievementPercentage,
                    'weighted_score' => $weightedScore,
                    'is_auto_synced' => $isAutoSynced,
                ]);

                $totalWeight = bcadd($totalWeight, $weight, self::SCORE_SCALE);
                $finalScore = bcadd($finalScore, $weightedScore, self::SCORE_SCALE);
            }

            $bonusResult = $this->bonusCalculator->calculate($employee, $finalScore);

            $lockedEvaluation->update([
                'total_weight' => $totalWeight,
                'final_score' => $finalScore,
                'grade' => $bonusResult['grade'],
                'bonus_amount' => $bonusResult['bonus_amount'],
            ]);

            return $lockedEvaluation->refresh()->load('items');
        });
    }

    /**
     * Submit a draft KPI evaluation for managerial review and approval.
     */
    public function submitEvaluation(KpiEvaluation $evaluation): KpiEvaluation
    {
        return DB::transaction(function () use ($evaluation): KpiEvaluation {
            $lockedEvaluation = KpiEvaluation::query()
                ->withoutGlobalScope('company')
                ->whereKey($evaluation->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedEvaluation instanceof KpiEvaluation) {
                throw new DomainException('KPI evaluation not found.');
            }

            if ($lockedEvaluation->isImmutable()) {
                throw new DomainException('Locked or payroll-applied KPI evaluations cannot be submitted.');
            }

            if ($lockedEvaluation->status !== KpiEvaluationStatus::Draft) {
                throw new DomainException('Only draft KPI evaluations can be submitted.');
            }

            $lockedEvaluation->update([
                'status' => KpiEvaluationStatus::Submitted,
            ]);

            return $lockedEvaluation->refresh();
        });
    }

    /**
     * Approve a draft or submitted KPI evaluation after verifying total indicator weight equals 100.00%.
     */
    public function approveEvaluation(KpiEvaluation $evaluation, User $approver): KpiEvaluation
    {
        return DB::transaction(function () use ($evaluation, $approver): KpiEvaluation {
            $lockedEvaluation = KpiEvaluation::query()
                ->withoutGlobalScope('company')
                ->whereKey($evaluation->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedEvaluation instanceof KpiEvaluation) {
                throw new DomainException('KPI evaluation not found.');
            }

            if ($lockedEvaluation->isImmutable()) {
                throw new DomainException('Locked or payroll-applied KPI evaluations cannot be approved.');
            }

            if (! in_array($lockedEvaluation->status, [KpiEvaluationStatus::Draft, KpiEvaluationStatus::Submitted], true)) {
                throw new DomainException('Only draft or submitted KPI evaluations can be approved.');
            }

            if (bccomp((string) $lockedEvaluation->total_weight, self::REQUIRED_APPROVAL_WEIGHT, self::SCORE_SCALE) !== 0) {
                throw new DomainException('Total KPI indicator weight must equal 100.00% before approval.');
            }

            $lockedEvaluation->update([
                'status' => KpiEvaluationStatus::Approved,
                'approved_by' => $approver->getKey(),
                'approved_at' => now(),
            ]);

            return $lockedEvaluation->refresh();
        });
    }

    /**
     * Transition an approved KPI evaluation to immutable locked status.
     */
    public function lockEvaluation(KpiEvaluation $evaluation): KpiEvaluation
    {
        return DB::transaction(function () use ($evaluation): KpiEvaluation {
            $lockedEvaluation = KpiEvaluation::query()
                ->withoutGlobalScope('company')
                ->whereKey($evaluation->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedEvaluation instanceof KpiEvaluation) {
                throw new DomainException('KPI evaluation not found.');
            }

            if ($lockedEvaluation->status === KpiEvaluationStatus::Locked) {
                throw new DomainException('KPI evaluation is already locked.');
            }

            if ($lockedEvaluation->status !== KpiEvaluationStatus::Approved) {
                throw new DomainException('Only approved KPI evaluations can be locked.');
            }

            $lockedEvaluation->update([
                'status' => KpiEvaluationStatus::Locked,
            ]);

            return $lockedEvaluation->refresh();
        });
    }

    /**
     * Filter active indicators matching the employee's department/position or general tenant-wide indicators.
     *
     * @param  Collection<int, KpiIndicator>  $indicators
     * @return Collection<int, KpiIndicator>
     */
    private function filterIndicatorsForEmployee(Collection $indicators, Employee $employee): Collection
    {
        return $indicators->filter(static function (KpiIndicator $indicator) use ($employee): bool {
            $departmentMatches = $indicator->department_id === null
                || (int) $indicator->department_id === (int) $employee->department_id;

            $positionMatches = $indicator->position_id === null
                || (int) $indicator->position_id === (int) $employee->position_id;

            return $departmentMatches && $positionMatches;
        });
    }

    /**
     * Compute item achievement percentage using bcmath with zero-target handling and a 120.00% upper cap.
     */
    private function calculateAchievementPercentage(string $targetValue, string $actualValue, bool $isHigherBetter): string
    {
        $target = bcadd($targetValue, '0', self::MONEY_SCALE);
        $actual = bcadd($actualValue, '0', self::MONEY_SCALE);

        if (bccomp($target, '0.0000', self::MONEY_SCALE) <= 0) {
            return '0.00';
        }

        if ($isHigherBetter) {
            if (bccomp($actual, '0.0000', self::MONEY_SCALE) <= 0) {
                return '0.00';
            }

            $raw = bcmul(bcdiv($actual, $target, self::DIVISION_SCALE), '100', self::SCORE_SCALE);

            return bccomp($raw, self::MAX_ACHIEVEMENT_PERCENTAGE, self::SCORE_SCALE) > 0
                ? self::MAX_ACHIEVEMENT_PERCENTAGE
                : $raw;
        }

        if (bccomp($actual, '0.0000', self::MONEY_SCALE) <= 0) {
            return '100.00';
        }

        $raw = bcmul(bcdiv($target, $actual, self::DIVISION_SCALE), '100', self::SCORE_SCALE);

        return bccomp($raw, self::MAX_ACHIEVEMENT_PERCENTAGE, self::SCORE_SCALE) > 0
            ? self::MAX_ACHIEVEMENT_PERCENTAGE
            : $raw;
    }
}
