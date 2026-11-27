<?php

declare(strict_types=1);

namespace Modules\Transaction\Enums;

/**
 * Defines occupancy statuses for branch POS tables.
 */
enum PosTableStatus: string
{
    case Available = 'available';
    case Occupied = 'occupied';
}
