<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Modules\Platform\Models\CompanyUser;

/**
 * Global authentication user model supporting Super Admin and multi-tenant panel access.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property bool $is_super_admin
 */
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * Define the companies this user belongs to via the company_user pivot.
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user')
            ->using(CompanyUser::class)
            ->withPivot(['branch_id', 'role_id', 'is_owner'])
            ->withTimestamps();
    }

    /**
     * Determine whether the user is permitted to access the given Filament panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => (bool) $this->is_super_admin,
            'app' => true,
            default => false,
        };
    }

    /**
     * Return all active tenant companies accessible by this user on the given panel.
     *
     * @return Collection<int, Company>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->companies()
            ->where('is_active', true)
            ->get();
    }

    /**
     * Verify whether the user can access the specified tenant company.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        /** Reject non-Company models and deactivated companies immediately. */
        if (! $tenant instanceof Company || ! $tenant->is_active) {
            return false;
        }

        return $this->companies()
            ->where('companies.id', $tenant->getKey())
            ->exists();
    }
}
