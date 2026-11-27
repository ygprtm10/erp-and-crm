<?php

declare(strict_types=1);

namespace Modules\Core\Data;

use Modules\Core\Models\Voucher;

/**
 * Immutable result of validating and applying a promotional voucher to an order subtotal.
 */
readonly class VoucherApplicationResult
{
    /**
     * Construct a voucher application result.
     */
    public function __construct(
        public Voucher $voucher,
        public string $subtotal,
        public string $discountAmount,
        public string $finalTotal,
    ) {}
}
