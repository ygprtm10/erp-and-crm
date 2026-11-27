<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unified CRM contact model representing customers, vendors, and/or members.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property bool $is_customer
 * @property bool $is_vendor
 * @property bool $is_member
 * @property string|null $lead_stage
 * @property string $points_balance
 */
class Contact extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'crm_contacts';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'email',
        'phone',
        'address',
        'is_customer',
        'is_vendor',
        'is_member',
        'lead_stage',
        'points_balance',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_customer' => 'boolean',
            'is_vendor' => 'boolean',
            'is_member' => 'boolean',
            'points_balance' => 'decimal:2',
        ];
    }

    /**
     * Define CRM follow-up interactions logged for this contact.
     */
    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class, 'contact_id');
    }
}
