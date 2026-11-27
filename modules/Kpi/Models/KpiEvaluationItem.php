<?php

declare(strict_types=1);

namespace Modules\Kpi\Models;

use App\Concerns\BelongsToCompany;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kpi\Enums\KpiMetricType;

/**
 * Line item within an employee KPI evaluation capturing target, actual, achievement, and weighted score.
 *
 * @property int $id
 * @property int $company_id
 * @property int $kpi_evaluation_id
 * @property int $kpi_indicator_id
 * @property string $indicator_name_snapshot
 * @property KpiMetricType $metric_type_snapshot
 * @property string $weight
 * @property string $target_value
 * @property string $actual_value
 * @property string $achievement_percentage
 * @property string $weighted_score
 * @property bool $is_auto_synced
 * @property string|null $notes
 */
class KpiEvaluationItem extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'kpi_evaluation_items';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'kpi_evaluation_id',
        'kpi_indicator_id',
        'indicator_name_snapshot',
        'metric_type_snapshot',
        'weight',
        'target_value',
        'actual_value',
        'achievement_percentage',
        'weighted_score',
        'is_auto_synced',
        'notes',
    ];

    /**
     * Prevent creating, editing, or deleting items on locked or payroll-applied evaluations.
     */
    protected static function booted(): void
    {
        $guardParentEvaluation = static function (self $item): void {
            $evaluation = KpiEvaluation::query()
                ->withoutGlobalScope('company')
                ->find($item->kpi_evaluation_id);

            if ($evaluation instanceof KpiEvaluation && $evaluation->isImmutable()) {
                throw new DomainException('Items of a locked or payroll-applied KPI evaluation cannot be modified.');
            }
        };

        static::creating($guardParentEvaluation);
        static::updating($guardParentEvaluation);
        static::deleting($guardParentEvaluation);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric_type_snapshot' => KpiMetricType::class,
            'weight' => 'decimal:2',
            'target_value' => 'decimal:4',
            'actual_value' => 'decimal:4',
            'achievement_percentage' => 'decimal:2',
            'weighted_score' => 'decimal:2',
            'is_auto_synced' => 'boolean',
        ];
    }

    /**
     * Define the parent KPI evaluation header.
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(KpiEvaluation::class, 'kpi_evaluation_id');
    }

    /**
     * Define the source KPI indicator definition.
     */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(KpiIndicator::class, 'kpi_indicator_id');
    }
}
