<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Platform\Database\Factories\CompanyFactory;
use Modules\Platform\Enums\CompanyModuleStatus;
use Modules\Platform\Models\CompanyModule;
use Modules\Platform\Models\CompanySetting;
use Modules\Platform\Models\CompanyUser;
use Modules\Platform\Models\SaasSubscription;
use Modules\Platform\Models\SaasTenantBankAccount;
use Modules\Platform\Models\SaasTenantWallet;
use Modules\Platform\Models\SaasWithdrawal;

/**
 * Represents a tenant organization within the multi-tenant SaaS application.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $custom_domain
 * @property string $business_type
 * @property bool $is_active
 */
class Company extends Model implements HasName
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'custom_domain',
        'business_type',
        'is_active',
    ];

    /**
     * Create a new factory instance for the Company model.
     */
    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Return the display name used by Filament's tenant switcher.
     */
    public function getFilamentName(): string
    {
        return $this->name;
    }

    /**
     * Define the users associated with this company via the company_user pivot.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->using(CompanyUser::class)
            ->withPivot(['branch_id', 'role_id', 'is_owner'])
            ->withTimestamps();
    }

    /**
     * Define the per-column settings record for this company.
     */
    public function settings(): HasOne
    {
        return $this->hasOne(CompanySetting::class);
    }

    /**
     * Define the module subscriptions assigned to this company.
     */
    public function companyModules(): HasMany
    {
        return $this->hasMany(CompanyModule::class);
    }

    /**
     * Define the SaaS subscription checkout records for this company.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(SaasSubscription::class);
    }

    /**
     * Define the escrow wallet owned by this company for Platform Midtrans settlements.
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(SaasTenantWallet::class);
    }

    /**
     * Define the verified withdrawal destination bank accounts belonging to this company.
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(SaasTenantBankAccount::class);
    }

    /**
     * Define the withdrawal requests submitted by this company.
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(SaasWithdrawal::class);
    }

    /**
     * Determine whether this company has an active or non-expired trial subscription for the given module slug.
     */
    public function hasActiveModule(string $moduleSlug): bool
    {
        $now = now();

        return $this->companyModules()
            ->withoutGlobalScope('company')
            ->whereIn('status', [
                CompanyModuleStatus::Active->value,
                CompanyModuleStatus::Trial->value,
            ])
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            })
            ->whereHas('module', function (Builder $query) use ($moduleSlug): void {
                $query->where('slug', $moduleSlug);
            })
            ->exists();
    }
}
