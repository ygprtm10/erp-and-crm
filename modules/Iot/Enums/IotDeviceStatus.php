<?php

declare(strict_types=1);

namespace Modules\Iot\Enums;

/**
 * Operational lifecycle statuses for registered IoT hardware devices.
 */
enum IotDeviceStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Maintenance = 'maintenance';

    /**
     * Return a human-readable label for the device status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Maintenance => 'Maintenance',
        };
    }
}
