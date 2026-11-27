<?php

declare(strict_types=1);

namespace Modules\Accounting\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Accounting\Enums\ExpenseStatus;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Contact;

/**
 * Operational expenditure record supporting unpaid accrual and paid settlement workflows.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $branch_id
 * @property int|null $contact_id
 * @property int $expense_account_id
 * @property int|null $payment_account_id
 * @property string $expense_number
 * @property string $amount
 * @property Carbon $expense_date
 * @property Carbon|null $due_date
 * @property string|null $receipt_path
 * @property ExpenseStatus $status
 * @property string|null $notes
 */
class Expense extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'acc_expenses';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'contact_id',
        'expense_account_id',
        'payment_account_id',
        'expense_number',
        'amount',
        'expense_date',
        'due_date',
        'receipt_path',
        'status',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'expense_date' => 'date',
            'due_date' => 'date',
            'status' => ExpenseStatus::class,
        ];
    }

    /**
     * Define the optional branch that incurred this expense.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Define the optional vendor/payee contact for this expense.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * Define the expense ledger account debited when this expense is paid.
     */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    /**
     * Define the cash or bank account credited when this expense is settled.
     */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }
}
