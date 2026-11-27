<?php

declare(strict_types=1);

namespace Modules\Platform\Data;

use Modules\Platform\Enums\GatewayOwner;

/**
 * Immutable value object holding resolved Midtrans credentials and ownership context.
 */
readonly class MidtransCredentials
{
    /**
     * Construct a validated set of Midtrans API credentials.
     */
    public function __construct(
        public GatewayOwner $gatewayOwner,
        public bool $isProduction,
        public ?string $merchantId,
        public string $serverKey,
        public string $clientKey,
    ) {}
}
