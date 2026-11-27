<?php

declare(strict_types=1);

namespace Modules\Hris\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Hris\Enums\PayslipItemType;

/**
 * Itemized component row belonging to an employee payslip.
 *
 * @property int $id
 * @property int $company_id
 * @property int $payslip_id
 * @property PayslipItemType $type
 * @property string $code
 * @property string $name
 * @property string $amount
 */
class PayslipItem extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'hr_payslip_items';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'payslip_id',
        'type',
        'code',
        'name',
        'amount',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PayslipItemType::class,
            'amount' => 'decimal:4',
        ];
    }

    /**
     * Define the parent payslip for this line item.
     */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class, 'payslip_id');
    }
}
