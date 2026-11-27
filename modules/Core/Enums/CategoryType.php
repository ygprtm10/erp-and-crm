<?php

declare(strict_types=1);

namespace Modules\Core\Enums;

/**
 * Categorizes catalog groupings for products, menu items, services, and memberships.
 */
enum CategoryType: string
{
    case Product = 'product';
    case Menu = 'menu';
    case Service = 'service';
    case Membership = 'membership';
}
