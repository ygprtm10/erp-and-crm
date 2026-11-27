<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Admin\Resources\SaasWithdrawalResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Modules\Platform\Filament\Admin\Resources\SaasWithdrawalResource;

/**
 * Displays a single tenant withdrawal request with completion and rejection actions.
 */
class ViewSaasWithdrawal extends ViewRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = SaasWithdrawalResource::class;

    /**
     * Expose approval actions in the view page header.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return SaasWithdrawalResource::getApprovalActions();
    }
}
