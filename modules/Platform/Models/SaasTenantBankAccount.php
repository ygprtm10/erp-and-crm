<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores verified destination bank accounts registered by a tenant for escrow withdrawals.
 *
 * @property int $id
 * @property int $company_id
 * @property string $bank_code
 * @property string $bank_name
 * @property string $account_number
 * @property string $account_holder_name
 * @property bool $is_primary
 * @property bool $is_verified
 */
class SaasTenantBankAccount extends Model
{
    use BelongsToCompany;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'bank_code',
        'bank_name',
        'account_number',
        'account_holder_name',
        'is_primary',
        'is_verified',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_verified' => 'boolean',
        ];
    }
}
