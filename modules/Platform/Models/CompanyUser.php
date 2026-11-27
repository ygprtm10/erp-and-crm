<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model connecting users to tenant companies with branch, role, and ownership metadata.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property int|null $branch_id
 * @property int|null $role_id
 * @property bool $is_owner
 */
class CompanyUser extends Pivot
{
    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The table associated with the pivot model.
     *
     * @var string
     */
    protected $table = 'company_user';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_owner' => 'boolean',
        ];
    }

    /**
     * Define the company associated with this membership record.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Define the user associated with this membership record.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
