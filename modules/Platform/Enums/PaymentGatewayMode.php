<?php

declare(strict_types=1);

namespace Modules\Platform\Enums;

/**
 * Defines whether tenant customer payments route through Platform or Tenant Midtrans accounts.
 */
enum PaymentGatewayMode: string
{
    case PlatformMidtrans = 'platform_midtrans';
    case OwnMidtrans = 'own_midtrans';

    /**
     * Return a human-readable label for UI display.
     */
    public function label(): string
    {
        return match ($this) {
            self::PlatformMidtrans => 'Midtrans Platform (Escrow)',
            self::OwnMidtrans => 'Midtrans Milik Sendiri',
        };
    }
}
