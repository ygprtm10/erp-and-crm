<?php

declare(strict_types=1);

namespace Modules\Kpi\Models;

use App\Concerns\BelongsToCompany;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\Payslip;
use Modules\Kpi\Enums\KpiEvaluationStatus;

/**
 * Per-employee KPI evaluation header enforcing immutability once locked or applied to a payslip.
 *
 * @property int $id
 * @property int $company_id
 * @property int $kpi_period_id
 * @property int $employee_id
 * @property int|null $evaluator_user_id
 * @property string $total_weight
 * @property string $final_score
 * @property string|null $grade
 * @property string $bonus_amount
 * @property int|null $bonus_applied_to_payslip_id
 * @property KpiEvaluationStatus $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $notes
 */
class KpiEvaluation extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'kpi_evaluations';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'kpi_period_id',
        'employee_id',
        'evaluator_user_id',
        'total_weight',
        'final_score',
        'grade',
        'bonus_amount',
        'bonus_applied_to_payslip_id',
        'status',
        'approved_by',
        'approved_at',
        'notes',
    ];

    /**
     * Enforce immutability once an evaluation is locked or already applied to a payroll payslip.
     */
    protected static function booted(): void
    {
        static::updating(function (self $evaluation): void {
            if ($evaluation->getRawOriginal('bonus_applied_to_payslip_id') !== null) {
                throw new DomainException('Payroll-applied KPI evaluations are immutable and cannot be modified.');
            }

            if ($evaluation->getRawOriginal('status') === KpiEvaluationStatus::Locked->value) {
                /** Allow payroll settlement to attach bonus_applied_to_payslip_id once on a pre-locked evaluation. */
                $dirtyKeys = array_keys($evaluation->getDirty());
                $allowedKeys = ['bonus_applied_to_payslip_id', 'updated_at'];

                if (array_diff($dirtyKeys, $allowedKeys) !== [] || $evaluation->bonus_applied_to_payslip_id === null) {
                    throw new DomainException('Locked KPI evaluations are immutable and cannot be modified.');
                }
            }
        });

        static::deleting(function (self $evaluation): void {
            if (
                $evaluation->getRawOriginal('status') === KpiEvaluationStatus::Locked->value
                || $evaluation->status === KpiEvaluationStatus::Locked
                || $evaluation->getRawOriginal('bonus_applied_to_payslip_id') !== null
                || $evaluation->bonus_applied_to_payslip_id !== null
            ) {
                throw new DomainException('Locked or payroll-applied KPI evaluations cannot be deleted.');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_weight' => 'decimal:2',
            'final_score' => 'decimal:2',
            'bonus_amount' => 'decimal:4',
            'status' => KpiEvaluationStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Determine whether this evaluation can no longer be edited or deleted.
     */
    public function isImmutable(): bool
    {
        return $this->status === KpiEvaluationStatus::Locked
            || $this->bonus_applied_to_payslip_id !== null;
    }

    /**
     * Define the KPI period for this evaluation.
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(KpiPeriod::class, 'kpi_period_id');
    }

    /**
     * Define the evaluated employee.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Define the user assigned as evaluator.
     */
    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_user_id');
    }

    /**
     * Define the user who approved this evaluation.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Define the payslip where this evaluation's bonus was paid out.
     */
    public function appliedPayslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class, 'bonus_applied_to_payslip_id');
    }

    /**
     * Define the indicator line items belonging to this evaluation.
     */
    public function items(): HasMany
    {
        return $this->hasMany(KpiEvaluationItem::class, 'kpi_evaluation_id');
    }
}
