<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global master table storing Indonesian PTKP statuses and TER categories.
 *
 * @property int $id
 * @property string $code
 * @property string $ter_category
 * @property string $annual_amount
 * @property int $effective_year
 */
class SysTaxPtkp extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sys_tax_ptkp';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'ter_category',
        'annual_amount',
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
            'annual_amount' => 'decimal:4',
            'effective_year' => 'integer',
        ];
    }
}
