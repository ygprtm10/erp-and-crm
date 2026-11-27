<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global master table storing progressive Article 17 PPh 21 tax tiers.
 *
 * @property int $id
 * @property string $min_pkp
 * @property string|null $max_pkp
 * @property string $rate_percent
 * @property int $effective_year
 */
class SysTaxProgressiveBracket extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'min_pkp',
        'max_pkp',
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
            'min_pkp' => 'decimal:4',
            'max_pkp' => 'decimal:4',
            'rate_percent' => 'decimal:4',
            'effective_year' => 'integer',
        ];
    }
}
