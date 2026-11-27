<?php

declare(strict_types=1);

namespace Modules\Transaction\Services;

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Contact;
use Modules\Core\Models\ProductVariant;
use Modules\Core\Models\Voucher;
use Modules\Core\Services\PricingCalculatorService;
use Modules\Platform\Models\CompanySetting;
use Modules\Transaction\Enums\KitchenStatus;
use Modules\Transaction\Enums\OrderChannel;
use Modules\Transaction\Enums\OrderStatus;
use Modules\Transaction\Enums\PaymentStatus;
use Modules\Transaction\Enums\PosShiftStatus;
use Modules\Transaction\Models\Order;
use Modules\Transaction\Models\OrderItem;
use Modules\Transaction\Models\OrderLog;
use Modules\Transaction\Models\OrderVoucher;
use Modules\Transaction\Models\PosShift;
use Modules\Transaction\Models\PosTable;

/**
 * Atomically creates unified orders with locked pricing/cost snapshots, voucher quotas, tax, and audit logs.
 */
class OrderCreationService
{
    private const MONEY_SCALE = 4;

    private const QUANTITY_SCALE = 2;

    /**
     * Inject the Core PricingCalculatorService dependency.
     */
    public function __construct(
        private readonly PricingCalculatorService $pricingCalculatorService,
    ) {}

    /**
     * Create an order, snapshot line items, apply optional voucher and tax, and record an initial audit log.
     *
     * @param  list<array{product_variant_id: int, qty: string|int|float, notes?: string|null, kitchen_status?: KitchenStatus|string|null}>  $items
     */
    public function createOrder(
        Company $company,
        Branch $branch,
        OrderChannel $channel,
        array $items,
        ?Contact $contact = null,
        ?PosShift $posShift = null,
        ?PosTable $posTable = null,
        ?string $voucherCode = null,
        ?User $createdBy = null,
        ?int $salespersonEmployeeId = null,
        ?string $notes = null,
        ?CarbonInterface $dueDate = null,
        ?CarbonInterface $estimatedReadyAt = null,
        ?string $rackLocation = null,
        ?string $orderNumber = null,
        OrderStatus $initialStatus = OrderStatus::Pending,
    ): Order {
        $this->assertRelationsOwnership($company, $branch, $contact, $posShift, $posTable);

        if ($items === []) {
            throw new InvalidArgumentException('An order must contain at least one line item.');
        }

        return DB::transaction(function () use (
            $company,
            $branch,
            $channel,
            $items,
            $contact,
            $posShift,
            $posTable,
            $voucherCode,
            $createdBy,
            $salespersonEmployeeId,
            $notes,
            $dueDate,
            $estimatedReadyAt,
            $rackLocation,
            $orderNumber,
            $initialStatus,
        ): Order {
            if ($posShift instanceof PosShift) {
                /** Re-verify shift status from database with lock to guard against stale instances. */
                $lockedShift = PosShift::query()
                    ->withoutGlobalScope('company')
                    ->whereKey($posShift->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedShift instanceof PosShift || $lockedShift->status !== PosShiftStatus::Open) {
                    throw new DomainException('Cannot attach an order to a closed cashier shift.');
                }
            }

            $preparedLines = $this->prepareOrderLines($company, $items);

            $grossSubtotal = '0.0000';
            $itemDiscountTotal = '0.0000';
            $netItemsSubtotal = '0.0000';

            foreach ($preparedLines as $line) {
                $grossSubtotal = bcadd($grossSubtotal, $line['line_gross'], self::MONEY_SCALE);
                $itemDiscountTotal = bcadd($itemDiscountTotal, $line['discount_amount'], self::MONEY_SCALE);
                $netItemsSubtotal = bcadd($netItemsSubtotal, $line['subtotal'], self::MONEY_SCALE);
            }

            $voucherApplication = null;
            $voucherDiscount = '0.0000';

            if ($voucherCode !== null && trim($voucherCode) !== '') {
                $voucherApplication = $this->pricingCalculatorService->applyVoucher(
                    company: $company,
                    voucherCode: $voucherCode,
                    orderSubtotal: $netItemsSubtotal,
                );

                /** Lock voucher row inside transaction before incrementing usage count to prevent race conditions. */
                $lockedVoucher = Voucher::query()
                    ->withoutGlobalScope('company')
                    ->whereKey($voucherApplication->voucher->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedVoucher instanceof Voucher || $lockedVoucher->used_count >= $lockedVoucher->quota) {
                    throw new DomainException('Voucher quota has been exhausted.');
                }

                $lockedVoucher->increment('used_count');
                $voucherDiscount = $voucherApplication->discountAmount;
            }

            $discountTotal = bcadd($itemDiscountTotal, $voucherDiscount, self::MONEY_SCALE);
            $taxableBase = bcsub($grossSubtotal, $discountTotal, self::MONEY_SCALE);

            if (bccomp($taxableBase, '0.0000', self::MONEY_SCALE) < 0) {
                $taxableBase = '0.0000';
            }

            $taxRate = $this->resolveCompanyTaxRate($company);
            $taxTotal = bcdiv(bcmul($taxableBase, $taxRate, self::MONEY_SCALE), '100', self::MONEY_SCALE);
            $grandTotal = bcadd($taxableBase, $taxTotal, self::MONEY_SCALE);

            $order = Order::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $company->getKey(),
                    'branch_id' => $branch->getKey(),
                    'contact_id' => $contact?->getKey(),
                    'pos_shift_id' => $posShift?->getKey(),
                    'pos_table_id' => $posTable?->getKey(),
                    'salesperson_employee_id' => $salespersonEmployeeId,
                    'order_number' => $orderNumber ?? $this->generateOrderNumber(),
                    'channel' => $channel,
                    'order_status' => $initialStatus,
                    'payment_status' => PaymentStatus::Unpaid,
                    'subtotal' => $grossSubtotal,
                    'discount_total' => $discountTotal,
                    'tax_total' => $taxTotal,
                    'grand_total' => $grandTotal,
                    'due_date' => $dueDate?->toDateString(),
                    'estimated_ready_at' => $estimatedReadyAt,
                    'rack_location' => $rackLocation,
                    'notes' => $notes,
                ]);

            foreach ($preparedLines as $line) {
                OrderItem::query()
                    ->withoutGlobalScope('company')
                    ->create([
                        'company_id' => $company->getKey(),
                        'order_id' => $order->getKey(),
                        'product_variant_id' => $line['product_variant_id'],
                        'item_name_snapshot' => $line['item_name_snapshot'],
                        'qty' => $line['qty'],
                        'unit_snapshot' => $line['unit_snapshot'],
                        'unit_price' => $line['unit_price'],
                        'cost_price_snapshot' => $line['cost_price_snapshot'],
                        'discount_amount' => $line['discount_amount'],
                        'subtotal' => $line['subtotal'],
                        'kitchen_status' => $line['kitchen_status'],
                        'notes' => $line['notes'],
                    ]);
            }

            if ($voucherApplication !== null) {
                OrderVoucher::query()
                    ->withoutGlobalScope('company')
                    ->create([
                        'company_id' => $company->getKey(),
                        'order_id' => $order->getKey(),
                        'voucher_id' => $voucherApplication->voucher->getKey(),
                        'voucher_code_snapshot' => $voucherApplication->voucher->code,
                        'discount_applied' => $voucherApplication->discountAmount,
                    ]);
            }

            OrderLog::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $company->getKey(),
                    'order_id' => $order->getKey(),
                    'event' => 'order_created',
                    'payload' => [
                        'order_number' => $order->order_number,
                        'channel' => $channel->value,
                        'subtotal' => $grossSubtotal,
                        'discount_total' => $discountTotal,
                        'tax_total' => $taxTotal,
                        'grand_total' => $grandTotal,
                        'items_count' => count($preparedLines),
                    ],
                    'created_by' => $createdBy?->getKey(),
                ]);

            return $order->load(['items', 'vouchers', 'logs']);
        });
    }

    /**
     * Cancel an unpaid and non-completed order inside a transaction and log the reason.
     */
    public function cancelOrder(Order $order, ?User $actor = null, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason): Order {
            $lockedOrder = Order::query()
                ->withoutGlobalScope('company')
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder instanceof Order) {
                throw new DomainException('Order not found.');
            }

            if ($lockedOrder->order_status === OrderStatus::Cancelled) {
                throw new DomainException('Order is already cancelled.');
            }

            if ($lockedOrder->order_status === OrderStatus::Completed) {
                throw new DomainException('Completed orders cannot be cancelled.');
            }

            if (in_array($lockedOrder->payment_status, [PaymentStatus::Paid, PaymentStatus::Refunded], true)) {
                throw new DomainException('Paid or refunded orders cannot be cancelled.');
            }

            $lockedOrder->update([
                'order_status' => OrderStatus::Cancelled,
            ]);

            OrderLog::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $lockedOrder->company_id,
                    'order_id' => $lockedOrder->getKey(),
                    'event' => 'order_cancelled',
                    'payload' => [
                        'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                    ],
                    'created_by' => $actor?->getKey(),
                ]);

            return $lockedOrder->refresh();
        });
    }

    /**
     * Validate tenant ownership across branch, contact, cashier shift, and POS table.
     */
    private function assertRelationsOwnership(
        Company $company,
        Branch $branch,
        ?Contact $contact,
        ?PosShift $posShift,
        ?PosTable $posTable,
    ): void {
        $companyId = (int) $company->getKey();

        if ((int) $branch->company_id !== $companyId) {
            throw new InvalidArgumentException('Branch does not belong to the specified company.');
        }

        if ($contact instanceof Contact && (int) $contact->company_id !== $companyId) {
            throw new InvalidArgumentException('Contact does not belong to the specified company.');
        }

        if ($posShift instanceof PosShift) {
            if ((int) $posShift->company_id !== $companyId || (int) $posShift->branch_id !== (int) $branch->getKey()) {
                throw new InvalidArgumentException('POS shift does not belong to the specified company and branch.');
            }

            if ($posShift->status !== PosShiftStatus::Open) {
                throw new DomainException('Cannot attach an order to a closed cashier shift.');
            }
        }

        if ($posTable instanceof PosTable) {
            if ((int) $posTable->company_id !== $companyId || (int) $posTable->branch_id !== (int) $branch->getKey()) {
                throw new InvalidArgumentException('POS table does not belong to the specified company and branch.');
            }
        }
    }

    /**
     * Resolve active variant prices and build immutable line snapshots with bcmath precision.
     *
     * @param  list<array{product_variant_id: int, qty: string|int|float, notes?: string|null, kitchen_status?: KitchenStatus|string|null}>  $items
     * @return list<array{
     *     product_variant_id: int,
     *     item_name_snapshot: string,
     *     qty: string,
     *     unit_snapshot: string,
     *     unit_price: string,
     *     cost_price_snapshot: string,
     *     discount_amount: string,
     *     subtotal: string,
     *     line_gross: string,
     *     kitchen_status: string|null,
     *     notes: string|null
     * }>
     */
    private function prepareOrderLines(Company $company, array $items): array
    {
        $prepared = [];

        foreach ($items as $item) {
            $qty = $this->normalizePositiveQuantity((string) ($item['qty'] ?? '0'));
            $variantId = (int) ($item['product_variant_id'] ?? 0);

            $variant = ProductVariant::query()
                ->withoutGlobalScope('company')
                ->with(['product' => static fn ($query) => $query->withoutGlobalScope('company')])
                ->where('company_id', $company->getKey())
                ->whereKey($variantId)
                ->first();

            if (! $variant instanceof ProductVariant || $variant->product === null) {
                throw new DomainException('Product variant does not exist for this company.');
            }

            if (! $variant->is_active) {
                throw new DomainException("Product variant [{$variant->sku}] is inactive.");
            }

            $pricing = $this->pricingCalculatorService->calculateVariantPrice($variant);

            $lineGross = bcmul($qty, $pricing->basePrice, self::MONEY_SCALE);
            $lineDiscount = bcmul($qty, $pricing->discountAmount, self::MONEY_SCALE);
            $lineSubtotal = bcsub($lineGross, $lineDiscount, self::MONEY_SCALE);

            $productName = trim((string) $variant->product->name);
            $variantName = trim((string) $variant->name);
            $itemNameSnapshot = $variantName !== '' ? "{$productName} - {$variantName}" : $productName;

            $kitchenStatus = $item['kitchen_status'] ?? null;
            if ($kitchenStatus instanceof KitchenStatus) {
                $kitchenStatus = $kitchenStatus->value;
            } elseif (is_string($kitchenStatus) && $kitchenStatus !== '') {
                $kitchenStatus = KitchenStatus::from($kitchenStatus)->value;
            } else {
                $kitchenStatus = null;
            }

            $prepared[] = [
                'product_variant_id' => (int) $variant->getKey(),
                'item_name_snapshot' => $itemNameSnapshot,
                'qty' => $qty,
                'unit_snapshot' => $variant->unit->value,
                'unit_price' => $pricing->basePrice,
                'cost_price_snapshot' => bcadd((string) $variant->cost_price, '0', self::MONEY_SCALE),
                'discount_amount' => $lineDiscount,
                'subtotal' => $lineSubtotal,
                'line_gross' => $lineGross,
                'kitchen_status' => $kitchenStatus,
                'notes' => isset($item['notes']) ? trim((string) $item['notes']) : null,
            ];
        }

        return $prepared;
    }

    /**
     * Resolve the configured company tax rate percentage as a decimal string.
     */
    private function resolveCompanyTaxRate(Company $company): string
    {
        $settings = CompanySetting::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->first();

        if (! $settings instanceof CompanySetting || $settings->tax_rate === null) {
            return '0.00';
        }

        return bcadd((string) $settings->tax_rate, '0', self::QUANTITY_SCALE);
    }

    /**
     * Validate numeric decimal syntax and enforce strictly positive quantity at scale 2.
     */
    private function normalizePositiveQuantity(string $value): string
    {
        $trimmed = trim($value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException('Invalid decimal format for quantity.');
        }

        $normalized = bcadd($trimmed, '0', self::QUANTITY_SCALE);

        if (bccomp($normalized, '0.00', self::QUANTITY_SCALE) <= 0) {
            throw new InvalidArgumentException('Order item quantity must be greater than zero.');
        }

        return $normalized;
    }

    /**
     * Generate a unique order number prefixed with TRX-.
     */
    private function generateOrderNumber(): string
    {
        return 'TRX-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
    }
}
