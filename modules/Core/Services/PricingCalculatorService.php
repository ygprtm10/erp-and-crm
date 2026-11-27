<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use App\Models\Company;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Modules\Core\Data\VariantPricingResult;
use Modules\Core\Data\VoucherApplicationResult;
use Modules\Core\Enums\DiscountType;
use Modules\Core\Models\ProductDiscount;
use Modules\Core\Models\ProductVariant;
use Modules\Core\Models\Voucher;

/**
 * Computes effective variant prices after active discounts and validates tenant voucher applications.
 */
class PricingCalculatorService
{
    private const MONEY_SCALE = 4;

    /**
     * Calculate the effective selling price for a product variant using its best active discount.
     */
    public function calculateVariantPrice(ProductVariant $variant, ?CarbonInterface $at = null): VariantPricingResult
    {
        $timestamp = $at ?? now();
        $basePrice = $this->formatMoney((string) $variant->selling_price, 'selling_price');

        /** Bypass tenant global scope so pricing works safely in background jobs and tests. */
        $activeDiscounts = ProductDiscount::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $variant->company_id)
            ->where('product_variant_id', $variant->getKey())
            ->where('is_active', true)
            ->where(function (Builder $query) use ($timestamp): void {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $timestamp);
            })
            ->where(function (Builder $query) use ($timestamp): void {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $timestamp);
            })
            ->get();

        $bestDiscountAmount = '0.0000';
        $appliedDiscount = null;

        /** Pick whichever active promotion yields the highest monetary savings. */
        foreach ($activeDiscounts as $discount) {
            $candidateAmount = $this->computeDiscountAmount(
                baseAmount: $basePrice,
                type: $discount->discount_type,
                value: (string) $discount->discount_value,
            );

            if (bccomp($candidateAmount, $bestDiscountAmount, self::MONEY_SCALE) > 0) {
                $bestDiscountAmount = $candidateAmount;
                $appliedDiscount = $discount;
            }
        }

        $finalPrice = bcsub($basePrice, $bestDiscountAmount, self::MONEY_SCALE);

        return new VariantPricingResult(
            basePrice: $basePrice,
            discountAmount: $bestDiscountAmount,
            finalPrice: $finalPrice,
            appliedDiscount: $appliedDiscount,
        );
    }

    /**
     * Validate a tenant voucher against schedule, quota, and minimum order amount, then compute savings.
     */
    public function applyVoucher(
        Company $company,
        string $voucherCode,
        string $orderSubtotal,
        ?CarbonInterface $at = null,
    ): VoucherApplicationResult {
        $timestamp = $at ?? now();
        $normalizedCode = strtoupper(trim($voucherCode));
        $subtotal = $this->formatMoney($orderSubtotal, 'order_subtotal');

        if (bccomp($subtotal, '0.0000', self::MONEY_SCALE) < 0) {
            throw new InvalidArgumentException('Order subtotal cannot be negative.');
        }

        if ($normalizedCode === '') {
            throw new InvalidArgumentException('Voucher code cannot be empty.');
        }

        $voucher = Voucher::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->whereRaw('UPPER(code) = ?', [$normalizedCode])
            ->first();

        if (! $voucher instanceof Voucher) {
            throw new DomainException('Voucher code is invalid for this company.');
        }

        if ($voucher->starts_at !== null && $voucher->starts_at->gt($timestamp)) {
            throw new DomainException('Voucher is not active yet.');
        }

        if ($voucher->ends_at !== null && $voucher->ends_at->lt($timestamp)) {
            throw new DomainException('Voucher has expired.');
        }

        if ($voucher->used_count >= $voucher->quota) {
            throw new DomainException('Voucher quota has been exhausted.');
        }

        $minOrderAmount = $this->formatMoney((string) $voucher->min_order_amount, 'min_order_amount');

        if (bccomp($subtotal, $minOrderAmount, self::MONEY_SCALE) < 0) {
            throw new DomainException('Order subtotal does not meet the voucher minimum amount.');
        }

        $discountAmount = $this->computeDiscountAmount(
            baseAmount: $subtotal,
            type: $voucher->discount_type,
            value: (string) $voucher->discount_value,
        );

        $finalTotal = bcsub($subtotal, $discountAmount, self::MONEY_SCALE);

        return new VoucherApplicationResult(
            voucher: $voucher,
            subtotal: $subtotal,
            discountAmount: $discountAmount,
            finalTotal: $finalTotal,
        );
    }

    /**
     * Compute a clamped non-negative monetary discount using bcmath precision.
     */
    private function computeDiscountAmount(string $baseAmount, DiscountType $type, string $value): string
    {
        $normalizedValue = $this->formatMoney($value, 'discount_value');

        if (bccomp($normalizedValue, '0.0000', self::MONEY_SCALE) <= 0) {
            return '0.0000';
        }

        $rawDiscount = match ($type) {
            DiscountType::Percent => bcdiv(bcmul($baseAmount, $normalizedValue, self::MONEY_SCALE), '100', self::MONEY_SCALE),
            DiscountType::Fixed => $normalizedValue,
        };

        /** Never allow computed discount to exceed the base monetary amount. */
        if (bccomp($rawDiscount, $baseAmount, self::MONEY_SCALE) > 0) {
            return $baseAmount;
        }

        return $rawDiscount;
    }

    /**
     * Assert numeric decimal syntax and normalize a monetary amount to 4 decimal places.
     */
    private function formatMoney(string $value, string $field): string
    {
        $trimmed = trim($value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException("Invalid decimal format for {$field}.");
        }

        return bcadd($trimmed, '0', self::MONEY_SCALE);
    }
}
