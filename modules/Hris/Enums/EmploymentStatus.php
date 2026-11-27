<?php

declare(strict_types=1);

namespace Modules\Hris\Enums;

/**
 * Employment lifecycle statuses for HRIS employee master records.
 */
enum EmploymentStatus: string
{
    case Active = 'active';
    case Probation = 'probation';
    case Resigned = 'resigned';
    case Terminated = 'terminated';

    /**
     * Return a human-readable label for the employment status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Probation => 'Probation',
            self::Resigned => 'Resigned',
            self::Terminated => 'Terminated',
        };
    }
}
