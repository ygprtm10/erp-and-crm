<?php

declare(strict_types=1);

namespace Modules\Core\Enums;

/**
 * Classifies inventory stock ledger movements across inbound, outbound, adjustments, and transfers.
 */
enum StockMovementType: string
{
    case In = 'in';
    case Out = 'out';
    case Adjustment = 'adjustment';
    case Transfer = 'transfer';
}
