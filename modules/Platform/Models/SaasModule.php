<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Defines a sellable SaaS module in the global catalog.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $monthly_price
 * @property array<int, string>|null $required_modules
 */
class SaasModule extends Model
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
        'required_modules',
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
            'required_modules' => 'array',
        ];
    }
}
