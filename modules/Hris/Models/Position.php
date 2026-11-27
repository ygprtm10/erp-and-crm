<?php

declare(strict_types=1);

namespace Modules\Hris\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-scoped job position master record with optional salary range bounds.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $department_id
 * @property string $code
 * @property string $name
 * @property string $base_salary_min
 * @property string $base_salary_max
 */
class Position extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'hr_positions';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'department_id',
        'code',
        'name',
        'base_salary_min',
        'base_salary_max',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_salary_min' => 'decimal:4',
            'base_salary_max' => 'decimal:4',
        ];
    }

    /**
     * Define the optional parent department for this position.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * Define employees holding this position.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'position_id');
    }
}
