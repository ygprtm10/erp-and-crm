<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Admin\Resources\SaasWithdrawalResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Platform\Filament\Admin\Resources\SaasWithdrawalResource;

/**
 * Lists tenant withdrawal requests in the Super Admin panel.
 */
class ListSaasWithdrawals extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = SaasWithdrawalResource::class;
}
