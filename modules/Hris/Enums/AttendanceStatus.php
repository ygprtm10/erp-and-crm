<?php

declare(strict_types=1);

namespace Modules\Hris\Enums;

/**
 * Daily attendance record statuses.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';
    case Leave = 'leave';
    case Sick = 'sick';
}
