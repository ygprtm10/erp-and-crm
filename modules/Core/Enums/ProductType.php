<?php

declare(strict_types=1);

namespace Modules\Core\Enums;

/**
 * Classifies core catalog products across goods, F&B menu, services, and memberships.
 */
enum ProductType: string
{
    case Goods = 'goods';
    case Menu = 'menu';
    case Service = 'service';
    case Membership = 'membership';
}
