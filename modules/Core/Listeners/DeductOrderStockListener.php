<?php

declare(strict_types=1);

namespace Modules\Core\Listeners;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\StockMovement;
use Modules\Core\Services\StockMovementService;
use Modules\Transaction\Events\OrderPaid;
use Modules\Transaction\Models\Order;

/**
 * Automatically deducts branch inventory for stock-tracked order items upon full payment settlement.
 */
class DeductOrderStockListener
{
    /**
     * Inject StockMovementService for locked inventory deductions.
     */
    public function __construct(
        private readonly StockMovementService $stockMovementService,
    ) {}

    /**
     * Deduct branch stock idempotently for each track_stock line item in the settled order.
     */
    public function handle(OrderPaid $event): void
    {
        $order = $event->order->loadMissing([
            'company',
            'branch' => static fn ($query) => $query->withoutGlobalScope('company'),
            'items' => static fn ($query) => $query->withoutGlobalScope('company'),
            'items.variant' => static fn ($query) => $query->withoutGlobalScope('company'),
            'items.variant.product' => static fn ($query) => $query->withoutGlobalScope('company'),
        ]);

        $company = $order->company;
        $branch = $order->branch;

        if (! $company instanceof Company || ! $branch instanceof Branch) {
            return;
        }

        /** Enforce order-level idempotency so replayed OrderPaid events never double-deduct stock. */
        $alreadyDeducted = StockMovement::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->getKey())
            ->exists();

        if ($alreadyDeducted) {
            return;
        }

        DB::transaction(function () use ($order, $company, $branch): void {
            foreach ($order->items as $item) {
                $variant = $item->variant;
                $product = $variant?->product;

                if ($variant === null || $product === null || ! $product->track_stock) {
                    continue;
                }

                $this->stockMovementService->recordStockOut(
                    company: $company,
                    branch: $branch,
                    variant: $variant,
                    quantity: (string) $item->qty,
                    referenceType: Order::class,
                    referenceId: (int) $order->getKey(),
                    notes: 'Order '.$order->order_number,
                );
            }
        });
    }
}
