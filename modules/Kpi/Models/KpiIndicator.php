<?php

declare(strict_types=1);

namespace Modules\Kpi\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Hris\Models\Department;
use Modules\Hris\Models\Position;
use Modules\Kpi\Enums\KpiMetricType;

/**
 * Tenant-scoped KPI indicator definition with optional department/position scoping and default targets.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $department_id
 * @property int|null $position_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property KpiMetricType $metric_type
 * @property string $unit
 * @property string $default_weight
 * @property string $default_target
 * @property bool $is_higher_better
 * @property bool $is_active
 */
class KpiIndicator extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'kpi_indicators';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'department_id',
        'position_id',
        'code',
        'name',
        'description',
        'metric_type',
        'unit',
        'default_weight',
        'default_target',
        'is_higher_better',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric_type' => KpiMetricType::class,
            'default_weight' => 'decimal:2',
            'default_target' => 'decimal:4',
            'is_higher_better' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Define the optional department scope for this indicator.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * Define the optional position scope for this indicator.
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    /**
     * Define evaluation items referencing this indicator.
     */
    public function evaluationItems(): HasMany
    {
        return $this->hasMany(KpiEvaluationItem::class, 'kpi_indicator_id');
    }
}
