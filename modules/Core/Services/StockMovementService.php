<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use App\Models\Company;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Enums\StockMovementType;
use Modules\Core\Models\Branch;
use Modules\Core\Models\InventoryStock;
use Modules\Core\Models\ProductVariant;
use Modules\Core\Models\StockMovement;

/**
 * Manages branch-level inventory balances and append-only movement ledgers with pessimistic locking.
 */
class StockMovementService
{
    private const QUANTITY_SCALE = 2;

    private const COST_SCALE = 4;

    /**
     * Increase inventory stock at a branch and append an inbound movement record.
     */
    public function recordStockIn(
        Company $company,
        Branch $branch,
        ProductVariant $variant,
        string $quantity,
        string $unitCost = '0.0000',
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $notes = null,
    ): StockMovement {
        $this->assertOwnership($company, $branch, $variant);
        $normalizedQty = $this->normalizePositiveQuantity($quantity);
        $normalizedCost = $this->normalizeNonNegativeCost($unitCost);

        return DB::transaction(function () use ($company, $branch, $variant, $normalizedQty, $normalizedCost, $referenceType, $referenceId, $notes): StockMovement {
            $stock = $this->lockOrCreateStock($company, $branch, $variant);
            $newQuantity = bcadd((string) $stock->quantity, $normalizedQty, self::QUANTITY_SCALE);

            $stock->update(['quantity' => $newQuantity]);

            return $this->appendMovement(
                company: $company,
                branch: $branch,
                variant: $variant,
                type: StockMovementType::In,
                qtyChange: $normalizedQty,
                unitCost: $normalizedCost,
                referenceType: $referenceType,
                referenceId: $referenceId,
                notes: $notes,
            );
        });
    }

    /**
     * Deduct inventory stock at a branch while rejecting negative balances.
     */
    public function recordStockOut(
        Company $company,
        Branch $branch,
        ProductVariant $variant,
        string $quantity,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $notes = null,
    ): StockMovement {
        $this->assertOwnership($company, $branch, $variant);
        $normalizedQty = $this->normalizePositiveQuantity($quantity);
        $unitCost = $this->normalizeNonNegativeCost((string) $variant->cost_price);

        return DB::transaction(function () use ($company, $branch, $variant, $normalizedQty, $unitCost, $referenceType, $referenceId, $notes): StockMovement {
            $stock = $this->lockOrCreateStock($company, $branch, $variant);
            $currentQty = $this->formatQuantity((string) $stock->quantity);

            /** Reject stock deductions that would drive inventory negative. */
            if (bccomp($currentQty, $normalizedQty, self::QUANTITY_SCALE) < 0) {
                throw new DomainException('Insufficient branch stock for outbound movement.');
            }

            $newQuantity = bcsub($currentQty, $normalizedQty, self::QUANTITY_SCALE);
            $stock->update(['quantity' => $newQuantity]);

            return $this->appendMovement(
                company: $company,
                branch: $branch,
                variant: $variant,
                type: StockMovementType::Out,
                qtyChange: '-'.$normalizedQty,
                unitCost: $unitCost,
                referenceType: $referenceType,
                referenceId: $referenceId,
                notes: $notes,
            );
        });
    }

    /**
     * Reconcile branch inventory to a non-negative target quantity and record the delta movement.
     */
    public function adjustStock(
        Company $company,
        Branch $branch,
        ProductVariant $variant,
        string $targetQuantity,
        ?string $notes = null,
    ): StockMovement {
        $this->assertOwnership($company, $branch, $variant);
        $normalizedTarget = $this->normalizeNonNegativeQuantity($targetQuantity);
        $unitCost = $this->normalizeNonNegativeCost((string) $variant->cost_price);

        return DB::transaction(function () use ($company, $branch, $variant, $normalizedTarget, $unitCost, $notes): StockMovement {
            $stock = $this->lockOrCreateStock($company, $branch, $variant);
            $currentQty = $this->formatQuantity((string) $stock->quantity);
            $delta = bcsub($normalizedTarget, $currentQty, self::QUANTITY_SCALE);

            $stock->update(['quantity' => $normalizedTarget]);

            return $this->appendMovement(
                company: $company,
                branch: $branch,
                variant: $variant,
                type: StockMovementType::Adjustment,
                qtyChange: $delta,
                unitCost: $unitCost,
                referenceType: null,
                referenceId: null,
                notes: $notes,
            );
        });
    }

    /**
     * Transfer inventory between two branches owned by the same company atomically.
     *
     * @return array{out: StockMovement, in: StockMovement}
     */
    public function transferStock(
        Company $company,
        Branch $sourceBranch,
        Branch $destinationBranch,
        ProductVariant $variant,
        string $quantity,
        ?string $notes = null,
    ): array {
        $this->assertOwnership($company, $sourceBranch, $variant);
        $this->assertOwnership($company, $destinationBranch, $variant);

        if ($sourceBranch->getKey() === $destinationBranch->getKey()) {
            throw new InvalidArgumentException('Source and destination branches must be different.');
        }

        $normalizedQty = $this->normalizePositiveQuantity($quantity);
        $unitCost = $this->normalizeNonNegativeCost((string) $variant->cost_price);

        return DB::transaction(function () use ($company, $sourceBranch, $destinationBranch, $variant, $normalizedQty, $unitCost, $notes): array {
            /** Lock branch stock rows in deterministic ID order to avoid deadlocks. */
            if ($sourceBranch->getKey() < $destinationBranch->getKey()) {
                $sourceStock = $this->lockOrCreateStock($company, $sourceBranch, $variant);
                $destinationStock = $this->lockOrCreateStock($company, $destinationBranch, $variant);
            } else {
                $destinationStock = $this->lockOrCreateStock($company, $destinationBranch, $variant);
                $sourceStock = $this->lockOrCreateStock($company, $sourceBranch, $variant);
            }

            $sourceQty = $this->formatQuantity((string) $sourceStock->quantity);

            if (bccomp($sourceQty, $normalizedQty, self::QUANTITY_SCALE) < 0) {
                throw new DomainException('Insufficient source branch stock for transfer.');
            }

            $sourceStock->update([
                'quantity' => bcsub($sourceQty, $normalizedQty, self::QUANTITY_SCALE),
            ]);

            $destinationStock->update([
                'quantity' => bcadd($this->formatQuantity((string) $destinationStock->quantity), $normalizedQty, self::QUANTITY_SCALE),
            ]);

            $outMovement = $this->appendMovement(
                company: $company,
                branch: $sourceBranch,
                variant: $variant,
                type: StockMovementType::Transfer,
                qtyChange: '-'.$normalizedQty,
                unitCost: $unitCost,
                referenceType: Branch::class,
                referenceId: $destinationBranch->getKey(),
                notes: $notes ?? 'Transfer out to '.$destinationBranch->name,
            );

            $inMovement = $this->appendMovement(
                company: $company,
                branch: $destinationBranch,
                variant: $variant,
                type: StockMovementType::Transfer,
                qtyChange: $normalizedQty,
                unitCost: $unitCost,
                referenceType: Branch::class,
                referenceId: $sourceBranch->getKey(),
                notes: $notes ?? 'Transfer in from '.$sourceBranch->name,
            );

            return [
                'out' => $outMovement,
                'in' => $inMovement,
            ];
        });
    }

    /**
     * Lock or provision the inv_stocks row for the given company, branch, and variant.
     */
    private function lockOrCreateStock(Company $company, Branch $branch, ProductVariant $variant): InventoryStock
    {
        $stock = InventoryStock::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('branch_id', $branch->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->lockForUpdate()
            ->first();

        if ($stock instanceof InventoryStock) {
            return $stock;
        }

        InventoryStock::query()
            ->withoutGlobalScope('company')
            ->create([
                'company_id' => $company->getKey(),
                'branch_id' => $branch->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => '0.00',
                'min_stock_alert' => '0.00',
            ]);

        /** @var InventoryStock $created */
        $created = InventoryStock::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('branch_id', $branch->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $created;
    }

    /**
     * Append a single immutable stock movement ledger entry.
     */
    private function appendMovement(
        Company $company,
        Branch $branch,
        ProductVariant $variant,
        StockMovementType $type,
        string $qtyChange,
        string $unitCost,
        ?string $referenceType,
        ?int $referenceId,
        ?string $notes,
    ): StockMovement {
        return StockMovement::query()
            ->withoutGlobalScope('company')
            ->create([
                'company_id' => $company->getKey(),
                'branch_id' => $branch->getKey(),
                'product_variant_id' => $variant->getKey(),
                'type' => $type,
                'qty_change' => $qtyChange,
                'unit_cost' => $unitCost,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'notes' => $notes,
            ]);
    }

    /**
     * Verify that both the branch and product variant belong to the target company.
     */
    private function assertOwnership(Company $company, Branch $branch, ProductVariant $variant): void
    {
        if ((int) $branch->company_id !== (int) $company->getKey()) {
            throw new InvalidArgumentException('Branch does not belong to the specified company.');
        }

        if ((int) $variant->company_id !== (int) $company->getKey()) {
            throw new InvalidArgumentException('Product variant does not belong to the specified company.');
        }
    }

    /**
     * Validate and normalize a strictly positive stock quantity.
     */
    private function normalizePositiveQuantity(string $value): string
    {
        $normalized = $this->formatQuantity($value);

        if (bccomp($normalized, '0.00', self::QUANTITY_SCALE) <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        return $normalized;
    }

    /**
     * Validate and normalize a non-negative stock quantity.
     */
    private function normalizeNonNegativeQuantity(string $value): string
    {
        $normalized = $this->formatQuantity($value);

        if (bccomp($normalized, '0.00', self::QUANTITY_SCALE) < 0) {
            throw new InvalidArgumentException('Target stock quantity cannot be negative.');
        }

        return $normalized;
    }

    /**
     * Validate and normalize a non-negative unit cost decimal to 4 places.
     */
    private function normalizeNonNegativeCost(string $value): string
    {
        $trimmed = trim($value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException('Invalid decimal format for unit cost.');
        }

        $normalized = bcadd($trimmed, '0', self::COST_SCALE);

        if (bccomp($normalized, '0.0000', self::COST_SCALE) < 0) {
            throw new InvalidArgumentException('Unit cost cannot be negative.');
        }

        return $normalized;
    }

    /**
     * Assert numeric decimal syntax and normalize a quantity string to 2 decimal places.
     */
    private function formatQuantity(string $value): string
    {
        $trimmed = trim($value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException('Invalid decimal format for quantity.');
        }

        return bcadd($trimmed, '0', self::QUANTITY_SCALE);
    }
}
