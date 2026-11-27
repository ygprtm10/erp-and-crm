<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a physical or operational branch location belonging to a tenant company.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $latitude
 * @property string|null $longitude
 * @property bool $is_main
 */
class Branch extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'core_branches';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'address',
        'phone',
        'latitude',
        'longitude',
        'is_main',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_main' => 'boolean',
        ];
    }

    /**
     * Define inventory stock records held at this branch.
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'branch_id');
    }
}
