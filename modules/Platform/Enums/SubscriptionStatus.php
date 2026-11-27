<?php

declare(strict_types=1);

namespace Modules\Platform\Enums;

/**
 * Represents payment settlement states for SaaS module/bundle subscriptions.
 */
enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
}
