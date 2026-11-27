<?php

declare(strict_types=1);

namespace Modules\Hris\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-employee payslip summary within a monthly payroll run.
 *
 * @property int $id
 * @property int $company_id
 * @property int $payroll_run_id
 * @property int $employee_id
 * @property string $base_salary
 * @property string $allowances_total
 * @property string $overtime_pay
 * @property string $bonus_total
 * @property string $gross_salary
 * @property string $pph21_amount
 * @property string $bpjs_kesehatan_employee
 * @property string $bpjs_kesehatan_company
 * @property string $bpjs_tk_employee
 * @property string $bpjs_tk_company
 * @property string $other_deductions
 * @property string $net_salary
 * @property int $attendance_days
 * @property int $absent_days
 * @property int $late_minutes
 * @property int $overtime_minutes
 * @property string|null $notes
 */
class Payslip extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'hr_payslips';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'payroll_run_id',
        'employee_id',
        'base_salary',
        'allowances_total',
        'overtime_pay',
        'bonus_total',
        'gross_salary',
        'pph21_amount',
        'bpjs_kesehatan_employee',
        'bpjs_kesehatan_company',
        'bpjs_tk_employee',
        'bpjs_tk_company',
        'other_deductions',
        'net_salary',
        'attendance_days',
        'absent_days',
        'late_minutes',
        'overtime_minutes',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:4',
            'allowances_total' => 'decimal:4',
            'overtime_pay' => 'decimal:4',
            'bonus_total' => 'decimal:4',
            'gross_salary' => 'decimal:4',
            'pph21_amount' => 'decimal:4',
            'bpjs_kesehatan_employee' => 'decimal:4',
            'bpjs_kesehatan_company' => 'decimal:4',
            'bpjs_tk_employee' => 'decimal:4',
            'bpjs_tk_company' => 'decimal:4',
            'other_deductions' => 'decimal:4',
            'net_salary' => 'decimal:4',
            'attendance_days' => 'integer',
            'absent_days' => 'integer',
            'late_minutes' => 'integer',
            'overtime_minutes' => 'integer',
        ];
    }

    /**
     * Define the parent payroll run header.
     */
    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * Define the employee owning this payslip.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Define itemized earning, deduction, and employer contribution lines on this payslip.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PayslipItem::class, 'payslip_id');
    }
}
