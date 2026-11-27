<?php

declare(strict_types=1);

namespace Modules\Platform\Enums;

/**
 * Identifies the owner of the Midtrans gateway handling a payment transaction.
 */
enum GatewayOwner: string
{
    case Platform = 'platform';
    case Tenant = 'tenant';
}
