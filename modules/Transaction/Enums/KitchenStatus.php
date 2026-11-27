<?php

declare(strict_types=1);

namespace Modules\Transaction\Enums;

/**
 * Defines kitchen display preparation statuses for order line items.
 */
enum KitchenStatus: string
{
    case Queued = 'queued';
    case Cooking = 'cooking';
    case Done = 'done';
}
