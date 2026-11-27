<?php

declare(strict_types=1);

namespace Modules\Hris\Enums;

/**
 * Supported employee leave request categories.
 */
enum LeaveType: string
{
    case Annual = 'annual';
    case Sick = 'sick';
    case Permission = 'permission';
    case Unpaid = 'unpaid';
}
