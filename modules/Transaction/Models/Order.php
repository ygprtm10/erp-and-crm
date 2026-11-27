<?php

declare(strict_types=1);

namespace Modules\Transaction\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Contact;
use Modules\Transaction\Enums\OrderChannel;
use Modules\Transaction\Enums\OrderStatus;
use Modules\Transaction\Enums\PaymentStatus;

/**
 * Unified transaction order header across POS, QR Order, Web, Invoice, Membership, and Laundry.
 *
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int|null $contact_id
 * @property int|null $pos_shift_id
 * @property int|null $pos_table_id
 * @property int|null $salesperson_employee_id
 * @property string $order_number
 * @property OrderChannel $channel
 * @property OrderStatus $order_status
 * @property PaymentStatus $payment_status
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $grand_total
 * @property Carbon|null $due_date
 * @property Carbon|null $estimated_ready_at
 * @property string|null $rack_location
 * @property Carbon|null $picked_up_at
 * @property string|null $notes
 */
class Order extends Model
{
    use BelongsToCompany;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'trx_orders';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'contact_id',
        'pos_shift_id',
        'pos_table_id',
        'salesperson_employee_id',
        'order_number',
        'channel',
        'order_status',
        'payment_status',
        'subtotal',
        'discount_total',
        'tax_total',
        'grand_total',
        'due_date',
        'estimated_ready_at',
        'rack_location',
        'picked_up_at',
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
            'channel' => OrderChannel::class,
            'order_status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'due_date' => 'date',
            'estimated_ready_at' => 'datetime',
            'picked_up_at' => 'datetime',
        ];
    }

    /**
     * Define the branch that owns this order.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Define the optional customer contact linked to this order.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * Define the optional cashier shift during which this order was placed.
     */
    public function posShift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    /**
     * Define the optional POS table associated with this order.
     */
    public function posTable(): BelongsTo
    {
        return $this->belongsTo(PosTable::class, 'pos_table_id');
    }

    /**
     * Define the line items belonging to this order.
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /**
     * Define operational workflow stages recorded for this order.
     */
    public function stages(): HasMany
    {
        return $this->hasMany(OrderStage::class, 'order_id');
    }

    /**
     * Define vouchers applied to this order.
     */
    public function vouchers(): HasMany
    {
        return $this->hasMany(OrderVoucher::class, 'order_id');
    }

    /**
     * Define payment records attached to this order.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'order_id');
    }

    /**
     * Define audit and webhook log entries for this order.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(OrderLog::class, 'order_id');
    }
}
