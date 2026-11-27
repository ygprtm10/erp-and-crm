<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use App\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Enums\InteractionChannel;

/**
 * Records a CRM communication log and optional follow-up schedule for a contact.
 *
 * @property int $id
 * @property int $company_id
 * @property int $contact_id
 * @property int|null $user_id
 * @property InteractionChannel $channel
 * @property string $notes
 * @property Carbon|null $next_follow_up_at
 */
class Interaction extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'crm_interactions';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'contact_id',
        'user_id',
        'channel',
        'notes',
        'next_follow_up_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => InteractionChannel::class,
            'next_follow_up_at' => 'datetime',
        ];
    }

    /**
     * Define the CRM contact targeted by this interaction.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * Define the staff user who recorded this interaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
