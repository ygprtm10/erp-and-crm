<?php

declare(strict_types=1);

namespace Modules\Core\Enums;

/**
 * Defines whether a discount or voucher deducts a percentage or a fixed currency amount.
 */
enum DiscountType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
