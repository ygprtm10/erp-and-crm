<?php

declare(strict_types=1);

namespace Modules\Transaction\Models;

use App\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Branch;
use Modules\Transaction\Enums\PosShiftStatus;

/**
 * Represents a cashier register shift tracking opening, expected, and actual cash balances.
 *
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $user_id
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property string $opening_cash
 * @property string $expected_cash
 * @property string|null $actual_cash
 * @property PosShiftStatus $status
 */
class PosShift extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pos_shifts';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'user_id',
        'opened_at',
        'closed_at',
        'opening_cash',
        'expected_cash',
        'actual_cash',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:4',
            'expected_cash' => 'decimal:4',
            'actual_cash' => 'decimal:4',
            'status' => PosShiftStatus::class,
        ];
    }

    /**
     * Define the branch where this shift operates.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Define the cashier user responsible for this shift.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Define orders processed during this shift.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'pos_shift_id');
    }
}
