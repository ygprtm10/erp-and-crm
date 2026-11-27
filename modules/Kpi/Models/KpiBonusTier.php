<?php

declare(strict_types=1);

namespace Modules\Kpi\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Kpi\Enums\KpiBonusType;

/**
 * Tenant-scoped KPI bonus tier mapping final score thresholds to grades and bonus values.
 *
 * @property int $id
 * @property int $company_id
 * @property string $min_score
 * @property string $max_score
 * @property string $grade
 * @property KpiBonusType $bonus_type
 * @property string $bonus_value
 * @property bool $is_active
 */
class KpiBonusTier extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'kpi_bonus_tiers';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'min_score',
        'max_score',
        'grade',
        'bonus_type',
        'bonus_value',
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
            'min_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'bonus_type' => KpiBonusType::class,
            'bonus_value' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
