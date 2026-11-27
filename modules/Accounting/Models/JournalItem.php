<?php

declare(strict_types=1);

namespace Modules\Accounting\Models;

use App\Concerns\BelongsToCompany;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Enums\JournalStatus;

/**
 * Individual debit or credit line item belonging to a general ledger journal.
 *
 * @property int $id
 * @property int $company_id
 * @property int $journal_id
 * @property int $account_id
 * @property string $debit
 * @property string $credit
 * @property string|null $memo
 */
class JournalItem extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'acc_journal_items';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'journal_id',
        'account_id',
        'debit',
        'credit',
        'memo',
    ];

    /**
     * Prevent mutating line items that belong to posted or voided journals.
     */
    protected static function booted(): void
    {
        $guard = static function (self $item): void {
            $journal = Journal::query()
                ->withoutGlobalScope('company')
                ->find($item->journal_id);

            if ($journal instanceof Journal && $journal->status !== JournalStatus::Draft) {
                throw new DomainException('Journal items belonging to a posted or void journal are immutable.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
        ];
    }

    /**
     * Define the parent general ledger journal header.
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    /**
     * Define the Chart of Accounts ledger posted to by this item.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
