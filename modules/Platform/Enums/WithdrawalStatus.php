<?php

declare(strict_types=1);

namespace Modules\Platform\Enums;

/**
 * Tracks the review and disbursement lifecycle of a tenant wallet withdrawal request.
 */
enum WithdrawalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Processing = 'processing';
    case Completed = 'completed';
    case Rejected = 'rejected';

    /**
     * Return a human-readable status label for Filament badges.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * Return the semantic Filament badge color for this withdrawal status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved, self::Processing => 'info',
            self::Completed => 'success',
            self::Rejected => 'danger',
        };
    }
}
