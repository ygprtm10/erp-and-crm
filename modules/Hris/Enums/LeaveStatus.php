<?php

declare(strict_types=1);

namespace Modules\Hris\Enums;

/**
 * Approval workflow states for employee leave requests.
 */
enum LeaveStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
