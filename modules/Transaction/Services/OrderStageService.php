<?php

declare(strict_types=1);

namespace Modules\Transaction\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Transaction\Models\Order;
use Modules\Transaction\Models\OrderLog;
use Modules\Transaction\Models\OrderStage;

/**
 * Tracks operational service and laundry workflow stage transitions for orders.
 */
class OrderStageService
{
    /**
     * Start a new operational stage on an order, optionally update rack location, and append an audit log entry.
     */
    public function startStage(
        Order $order,
        string $stageName,
        ?int $handledByEmployeeId = null,
        ?string $notes = null,
        ?User $actor = null,
        ?string $rackLocation = null,
    ): OrderStage {
        $trimmedStageName = trim($stageName);

        if ($trimmedStageName === '') {
            throw new InvalidArgumentException('Stage name is required.');
        }

        return DB::transaction(function () use ($order, $trimmedStageName, $handledByEmployeeId, $notes, $actor, $rackLocation): OrderStage {
            $lockedOrder = Order::query()
                ->withoutGlobalScope('company')
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder instanceof Order) {
                throw new DomainException('Order not found.');
            }

            $normalizedRackLocation = $rackLocation !== null ? trim($rackLocation) : null;

            if ($normalizedRackLocation !== null && $normalizedRackLocation !== '') {
                $lockedOrder->update([
                    'rack_location' => $normalizedRackLocation,
                ]);
            }

            $stage = OrderStage::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $lockedOrder->company_id,
                    'order_id' => $lockedOrder->getKey(),
                    'handled_by_employee_id' => $handledByEmployeeId,
                    'stage_name' => $trimmedStageName,
                    'started_at' => now(),
                    'notes' => $notes,
                ]);

            OrderLog::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $lockedOrder->company_id,
                    'order_id' => $lockedOrder->getKey(),
                    'event' => 'stage_started',
                    'payload' => [
                        'stage_id' => $stage->getKey(),
                        'stage_name' => $trimmedStageName,
                        'handled_by_employee_id' => $handledByEmployeeId,
                        'rack_location' => $normalizedRackLocation !== '' ? $normalizedRackLocation : null,
                    ],
                    'created_by' => $actor?->getKey(),
                ]);

            return $stage;
        });
    }

    /**
     * Mark an active order stage as completed and append an audit log entry.
     */
    public function completeStage(
        OrderStage $stage,
        ?string $notes = null,
        ?User $actor = null,
    ): OrderStage {
        return DB::transaction(function () use ($stage, $notes, $actor): OrderStage {
            $lockedStage = OrderStage::query()
                ->withoutGlobalScope('company')
                ->whereKey($stage->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedStage instanceof OrderStage) {
                throw new DomainException('Order stage not found.');
            }

            if ($lockedStage->completed_at !== null) {
                throw new DomainException('Order stage has already been completed.');
            }

            $lockedStage->update([
                'completed_at' => now(),
                'notes' => $notes ?? $lockedStage->notes,
            ]);

            OrderLog::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $lockedStage->company_id,
                    'order_id' => $lockedStage->order_id,
                    'event' => 'stage_completed',
                    'payload' => [
                        'stage_id' => $lockedStage->getKey(),
                        'stage_name' => $lockedStage->stage_name,
                        'completed_at' => $lockedStage->completed_at?->toIso8601String(),
                    ],
                    'created_by' => $actor?->getKey(),
                ]);

            return $lockedStage->refresh();
        });
    }
}
