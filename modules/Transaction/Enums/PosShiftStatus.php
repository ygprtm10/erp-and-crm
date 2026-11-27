<?php

declare(strict_types=1);

namespace Modules\Transaction\Enums;

/**
 * Defines cashier shift lifecycle statuses.
 */
enum PosShiftStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
