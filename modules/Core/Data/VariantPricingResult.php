<?php

declare(strict_types=1);

namespace Modules\Core\Data;

use Modules\Core\Models\ProductDiscount;

/**
 * Immutable pricing breakdown for a product variant after applying promotional discounts.
 */
readonly class VariantPricingResult
{
    /**
     * Construct a variant pricing calculation result.
     */
    public function __construct(
        public string $basePrice,
        public string $discountAmount,
        public string $finalPrice,
        public ?ProductDiscount $appliedDiscount,
    ) {}
}
