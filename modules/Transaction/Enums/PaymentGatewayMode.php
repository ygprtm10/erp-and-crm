<?php

declare(strict_types=1);

namespace Modules\Transaction\Enums;

/**
 * Defines routing modes for processing transaction payments.
 */
enum PaymentGatewayMode: string
{
    case Manual = 'manual';
    case PlatformMidtrans = 'platform_midtrans';
    case OwnMidtrans = 'own_midtrans';
}
