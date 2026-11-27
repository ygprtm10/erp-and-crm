<?php

declare(strict_types=1);

namespace Modules\Accounting\Models;

use App\Concerns\BelongsToCompany;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Core\Models\Branch;

/**
 * General ledger journal header enforcing immutability once posted or voided.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $branch_id
 * @property string $journal_number
 * @property Carbon $journal_date
 * @property string $description
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property JournalStatus $status
 * @property int|null $created_by
 */
class Journal extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'acc_journals';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'journal_number',
        'journal_date',
        'description',
        'reference_type',
        'reference_id',
        'status',
        'created_by',
    ];

    /**
     * Register model integrity guards protecting posted and voided journals from direct edits or deletion.
     */
    protected static function booted(): void
    {
        static::updating(function (self $journal): void {
            $rawOriginalStatus = $journal->getRawOriginal('status');

            if ($rawOriginalStatus === JournalStatus::Void->value) {
                throw new DomainException('Void journals are immutable and cannot be modified.');
            }

            if ($rawOriginalStatus === JournalStatus::Posted->value) {
                $dirtyKeys = array_keys($journal->getDirty());
                $isVoidTransitionOnly = $dirtyKeys === ['status'] && $journal->status === JournalStatus::Void;

                if (! $isVoidTransitionOnly) {
                    throw new DomainException('Posted journals cannot be modified directly.');
                }
            }
        });

        static::deleting(function (self $journal): void {
            if ($journal->status !== JournalStatus::Draft) {
                throw new DomainException('Only draft journals can be deleted.');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'journal_date' => 'date',
            'status' => JournalStatus::class,
        ];
    }

    /**
     * Define the optional branch associated with this journal entry.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Define the user who created this journal entry.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Define the balanced debit/credit line items belonging to this journal.
     */
    public function items(): HasMany
    {
        return $this->hasMany(JournalItem::class, 'journal_id');
    }

    /**
     * Define the optional polymorphic source document that originated this journal.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
