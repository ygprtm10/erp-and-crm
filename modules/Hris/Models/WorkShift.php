<?php

declare(strict_types=1);

namespace Modules\Hris\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-scoped work shift schedule defining expected clock-in/out times and lateness tolerance.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $clock_in_time
 * @property string $clock_out_time
 * @property int $late_tolerance_minutes
 * @property bool $is_overnight
 */
class WorkShift extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'hr_work_shifts';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
        'clock_in_time',
        'clock_out_time',
        'late_tolerance_minutes',
        'is_overnight',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'late_tolerance_minutes' => 'integer',
            'is_overnight' => 'boolean',
        ];
    }

    /**
     * Define employees assigned to this default work shift.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'work_shift_id');
    }

    /**
     * Define attendance logs recorded under this work shift.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'work_shift_id');
    }
}
