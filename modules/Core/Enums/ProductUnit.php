<?php

declare(strict_types=1);

namespace Modules\Core\Enums;

/**
 * Standardizes selling and stock measurement units for product variants.
 */
enum ProductUnit: string
{
    case Pcs = 'pcs';
    case Kg = 'kg';
    case M2 = 'm2';
    case Visit = 'visit';
    case Paket = 'paket';
}
