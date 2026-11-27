<?php

declare(strict_types=1);

namespace Modules\Iot\Data;

use Modules\Iot\Models\IotDevice;

/**
 * Immutable data transfer object returning a newly provisioned IoT device and its one-time plaintext token.
 */
readonly class ProvisionedDeviceResult
{
    /**
     * Create a new provisioned device result instance.
     */
    public function __construct(
        public IotDevice $device,
        public string $plainToken,
    ) {}
}
