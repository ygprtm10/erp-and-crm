<?php

declare(strict_types=1);

namespace Modules\Hris\Models;

use App\Concerns\BelongsToCompany;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Branch;
use Modules\Hris\Enums\PayrollRunStatus;

/**
 * Monthly payroll batch header enforcing immutability once transitioned to paid status.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $branch_id
 * @property string $payroll_number
 * @property int $period_month
 * @property int $period_year
 * @property PayrollRunStatus $status
 * @property bool $apply_pph21
 * @property bool $apply_bpjs
 * @property string $total_gross
 * @property string $total_other_deductions
 * @property string $total_pph21
 * @property string $total_bpjs_employee
 * @property string $total_bpjs_company
 * @property string $total_net
 * @property int|null $payment_account_id
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $paid_at
 * @property string|null $notes
 */
class PayrollRun extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'hr_payroll_runs';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'payroll_number',
        'period_month',
        'period_year',
        'status',
        'apply_pph21',
        'apply_bpjs',
        'total_gross',
        'total_other_deductions',
        'total_pph21',
        'total_bpjs_employee',
        'total_bpjs_company',
        'total_net',
        'payment_account_id',
        'approved_by',
        'approved_at',
        'paid_at',
        'notes',
    ];

    /**
     * Enforce immutability so paid payroll runs cannot be edited directly or deleted.
     */
    protected static function booted(): void
    {
        static::updating(function (self $payrollRun): void {
            if ($payrollRun->getRawOriginal('status') === PayrollRunStatus::Paid->value) {
                throw new DomainException('Paid payroll runs are immutable and cannot be modified.');
            }
        });

        static::deleting(function (self $payrollRun): void {
            if ($payrollRun->status === PayrollRunStatus::Paid) {
                throw new DomainException('Paid payroll runs cannot be deleted.');
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
            'period_month' => 'integer',
            'period_year' => 'integer',
            'status' => PayrollRunStatus::class,
            'apply_pph21' => 'boolean',
            'apply_bpjs' => 'boolean',
            'total_gross' => 'decimal:4',
            'total_other_deductions' => 'decimal:4',
            'total_pph21' => 'decimal:4',
            'total_bpjs_employee' => 'decimal:4',
            'total_bpjs_company' => 'decimal:4',
            'total_net' => 'decimal:4',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Define the optional branch scope for this payroll run.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Define the payment account used when settling this payroll run.
     */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }

    /**
     * Define the user who approved this payroll run.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Define individual employee payslips belonging to this payroll run.
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'payroll_run_id');
    }
}
