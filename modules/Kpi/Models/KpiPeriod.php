<?php

declare(strict_types=1);

namespace Modules\Kpi\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Kpi\Enums\KpiPeriodStatus;
use Modules\Kpi\Enums\KpiPeriodType;

/**
 * Tenant-scoped KPI evaluation cycle with optional payroll period linkage.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property KpiPeriodType $period_type
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property KpiPeriodStatus $status
 * @property int|null $payroll_period_month
 * @property int|null $payroll_period_year
 * @property string|null $notes
 */
class KpiPeriod extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'kpi_periods';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
        'period_type',
        'start_date',
        'end_date',
        'status',
        'payroll_period_month',
        'payroll_period_year',
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
            'period_type' => KpiPeriodType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => KpiPeriodStatus::class,
            'payroll_period_month' => 'integer',
            'payroll_period_year' => 'integer',
        ];
    }

    /**
     * Define employee KPI evaluations belonging to this period.
     */
    public function evaluations(): HasMany
    {
        return $this->hasMany(KpiEvaluation::class, 'kpi_period_id');
    }
}
