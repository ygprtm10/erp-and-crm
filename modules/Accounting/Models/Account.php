<?php

declare(strict_types=1);

namespace Modules\Accounting\Models;

use App\Concerns\BelongsToCompany;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\NormalBalance;

/**
 * Chart of Accounts entry protecting system accounts and referenced accounts from deletion.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $parent_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property NormalBalance $normal_balance
 * @property bool $is_system
 */
class Account extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'acc_accounts';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name',
        'type',
        'normal_balance',
        'is_system',
    ];

    /**
     * Register model integrity guards preventing deletion of system or used accounts.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $account): void {
            if ($account->is_system) {
                throw new DomainException('System accounts cannot be deleted.');
            }

            if ($account->journalItems()->withoutGlobalScope('company')->exists()) {
                throw new DomainException('Cannot delete an account that has existing journal items.');
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
            'type' => AccountType::class,
            'normal_balance' => NormalBalance::class,
            'is_system' => 'boolean',
        ];
    }

    /**
     * Define the optional parent account in the Chart of Accounts hierarchy.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Define child sub-accounts nested under this account.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Define general ledger journal items referencing this account.
     */
    public function journalItems(): HasMany
    {
        return $this->hasMany(JournalItem::class, 'account_id');
    }

    /**
     * Define operational expenses categorized under this expense account.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'expense_account_id');
    }

    /**
     * Define operational expenses paid out of this cash/bank account.
     */
    public function paymentExpenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'payment_account_id');
    }
}
