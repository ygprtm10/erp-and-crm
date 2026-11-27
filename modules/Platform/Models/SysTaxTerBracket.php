<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global master table storing monthly PPh 21 Effective Average Rate (TER) brackets.
 *
 * @property int $id
 * @property string $ter_category
 * @property string $min_gross
 * @property string|null $max_gross
 * @property string $rate_percent
 * @property int $effective_year
 */
class SysTaxTerBracket extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ter_category',
        'min_gross',
        'max_gross',
        'rate_percent',
        'effective_year',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_gross' => 'decimal:4',
            'max_gross' => 'decimal:4',
            'rate_percent' => 'decimal:4',
            'effective_year' => 'integer',
        ];
    }
}
