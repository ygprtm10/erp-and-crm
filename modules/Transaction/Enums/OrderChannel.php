<?php

declare(strict_types=1);

namespace Modules\Transaction\Enums;

/**
 * Defines supported origination channels for unified transactions.
 */
enum OrderChannel: string
{
    case Pos = 'pos';
    case QrOrder = 'qr_order';
    case Web = 'web';
    case Invoice = 'invoice';
    case Membership = 'membership';
    case Laundry = 'laundry';
}
