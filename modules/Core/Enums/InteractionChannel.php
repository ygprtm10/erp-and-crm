<?php

declare(strict_types=1);

namespace Modules\Core\Enums;

/**
 * Identifies the communication channel used for a CRM contact interaction.
 */
enum InteractionChannel: string
{
    case Wa = 'wa';
    case Call = 'call';
    case Visit = 'visit';
    case Email = 'email';
}
