<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Defines an industry bundle containing a curated set of SaaS modules.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $monthly_price
 * @property string $annual_price
 * @property array<int, int>|null $module_ids
 * @property bool $is_active
 */
class SaasBundle extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'monthly_price',
        'annual_price',
        'module_ids',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:4',
            'annual_price' => 'decimal:4',
            'module_ids' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
