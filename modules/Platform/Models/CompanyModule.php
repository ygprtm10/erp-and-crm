<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Enums\CompanyModuleStatus;

/**
 * Tracks module entitlements, trial/active status, expiration, and quota limits per company.
 *
 * @property int $id
 * @property int $company_id
 * @property int $module_id
 * @property CompanyModuleStatus $status
 * @property Carbon|null $expires_at
 * @property array<string, mixed>|null $limits
 */
class CompanyModule extends Model
{
    use BelongsToCompany;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'module_id',
        'status',
        'expires_at',
        'limits',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyModuleStatus::class,
            'expires_at' => 'datetime',
            'limits' => 'array',
        ];
    }

    /**
     * Define the SaaS module definition referenced by this entitlement.
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(SaasModule::class, 'module_id');
    }
}
