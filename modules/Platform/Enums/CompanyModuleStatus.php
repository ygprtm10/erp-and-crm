<?php

declare(strict_types=1);

namespace Modules\Platform\Enums;

/**
 * Represents the subscription lifecycle state of a module assigned to a company.
 */
enum CompanyModuleStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Expired = 'expired';
}
