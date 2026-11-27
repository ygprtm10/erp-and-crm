<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Company;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enforces automatic multi-tenant scoping and assignment via company_id.
 */
trait BelongsToCompany
{
    /**
     * Register the tenant global scope and auto-populate company_id on creation.
     */
    public static function bootBelongsToCompany(): void
    {
        /** Automatically filter all queries to the active Filament tenant. */
        static::addGlobalScope('company', function (Builder $builder): void {
            $tenant = Filament::getTenant();

            if ($tenant === null) {
                return;
            }

            $builder->where($builder->getModel()->getTable().'.company_id', $tenant->getKey());
        });

        /** Assign the active tenant ID when creating a new operational record. */
        static::creating(function (Model $model): void {
            if (! empty($model->getAttribute('company_id'))) {
                return;
            }

            $tenant = Filament::getTenant();

            if ($tenant === null) {
                return;
            }

            $model->setAttribute('company_id', $tenant->getKey());
        });
    }

    /**
     * Define the owning company relationship for this tenant-scoped model.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
