<?php

declare(strict_types=1);

namespace Modules\Iot\Enums;

/**
 * Processing outcome statuses for incoming IoT attendance tap logs.
 */
enum IotLogStatus: string
{
    case Processed = 'processed';
    case UnregisteredUid = 'unregistered_uid';
    case IgnoredCooldown = 'ignored_cooldown';
    case Failed = 'failed';
}
